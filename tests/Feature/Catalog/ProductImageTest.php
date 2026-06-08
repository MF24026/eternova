<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class ProductImageTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $ownerA;

    private Product $productA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        Storage::fake(config('catalog.image_disk'));

        $this->tenantA = Tenant::factory()->create(['slug' => 'img-test-tenant-a']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'img-test-tenant-b']);
        $this->ownerA = User::factory()->forTenant($this->tenantA, role: 'owner')->create();
        $this->productA = Product::factory()->forTenant($this->tenantA)->create(['slug' => 'img-test-product']);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function test_owner_can_upload_image_and_three_sizes_are_generated(): void
    {
        $response = $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 800, 800);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['thumbnail', 'medium', 'full'],
            ]);

        $disk = config('catalog.image_disk');
        Storage::disk($disk)->assertExists($this->pathOnDisk($response->json('data.thumbnail')));
        Storage::disk($disk)->assertExists($this->pathOnDisk($response->json('data.medium')));
        Storage::disk($disk)->assertExists($this->pathOnDisk($response->json('data.full')));
    }

    public function test_upload_appends_image_to_product_gallery(): void
    {
        $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 800, 800);

        $this->productA->refresh();

        $this->assertCount(1, $this->productA->gallery);
        $this->assertArrayHasKey('thumbnail', $this->productA->gallery[0]);
        $this->assertArrayHasKey('medium', $this->productA->gallery[0]);
        $this->assertArrayHasKey('full', $this->productA->gallery[0]);
    }

    public function test_upload_rejects_file_over_5mb(): void
    {
        // Create a fake file that exceeds 5 MB (>5120 KB).
        $file = UploadedFile::fake()->create('big.jpg', 5121, 'image/jpeg');

        $this->tenantPostJson($this->tenantA, $this->ownerA, "/api/v1/products/{$this->productA->id}/images", [], [])
            ->assertStatus(422);

        // Use multipart directly via call()
        $response = $this->actingAs($this->ownerA)
            ->call(
                'POST',
                $this->tenantUrl($this->tenantA, "/api/v1/products/{$this->productA->id}/images"),
                [],
                [],
                ['image' => $file],
                ['HTTP_ACCEPT' => 'application/json'],
            );

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('image');
    }

    public function test_upload_rejects_non_image_mime(): void
    {
        $pdf = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->ownerA)
            ->call(
                'POST',
                $this->tenantUrl($this->tenantA, "/api/v1/products/{$this->productA->id}/images"),
                [],
                [],
                ['image' => $pdf],
                ['HTTP_ACCEPT' => 'application/json'],
            );

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('image');
    }

    public function test_upload_rejects_image_below_min_dimension(): void
    {
        // 300x300 is below the 400px minimum.
        $response = $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 300, 300);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('image');
    }

    public function test_uploaded_image_is_stored_under_tenant_and_product_path(): void
    {
        $response = $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 800, 800);
        $response->assertStatus(201);

        $fullPath = $this->pathOnDisk($response->json('data.full'));

        $this->assertStringContainsString(
            "tenants/{$this->tenantA->id}/products/{$this->productA->id}/full/",
            $fullPath,
        );
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_image_removes_gallery_entry_and_all_three_files(): void
    {
        $disk = config('catalog.image_disk');

        $uploadResponse = $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 800, 800);
        $uploadResponse->assertStatus(201);

        $fullUrl = $uploadResponse->json('data.full');
        $thumbUrl = $uploadResponse->json('data.thumbnail');
        $mediumUrl = $uploadResponse->json('data.medium');

        $this->tenantDeleteJsonWithData($this->tenantA, $this->ownerA, "/api/v1/products/{$this->productA->id}/images", ['url' => $fullUrl])
            ->assertStatus(204);

        $this->productA->refresh();
        $this->assertCount(0, $this->productA->gallery);

        Storage::disk($disk)->assertMissing($this->pathOnDisk($fullUrl));
        Storage::disk($disk)->assertMissing($this->pathOnDisk($thumbUrl));
        Storage::disk($disk)->assertMissing($this->pathOnDisk($mediumUrl));
    }

    // ── Set default ───────────────────────────────────────────────────────────

    public function test_set_default_updates_product_default_image_url(): void
    {
        $uploadResponse = $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 800, 800);
        $fullUrl = $uploadResponse->json('data.full');

        $response = $this->tenantPatchJson(
            $this->tenantA,
            $this->ownerA,
            "/api/v1/products/{$this->productA->id}/images/set-default",
            ['url' => $fullUrl],
        );

        $response->assertOk();

        $this->productA->refresh();
        $this->assertSame($fullUrl, $this->productA->default_image_url);
    }

    // ── Reorder ───────────────────────────────────────────────────────────────

    public function test_reorder_gallery_changes_order(): void
    {
        $first = $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 800, 800)->json('data.full');
        $second = $this->uploadFakeImage($this->tenantA, $this->ownerA, $this->productA, 800, 800)->json('data.full');

        // Reverse the order.
        $this->tenantPatchJson(
            $this->tenantA,
            $this->ownerA,
            "/api/v1/products/{$this->productA->id}/images/reorder",
            ['urls' => [$second, $first]],
        )->assertStatus(204);

        $this->productA->refresh();
        $this->assertSame($second, $this->productA->gallery[0]['full']);
        $this->assertSame($first, $this->productA->gallery[1]['full']);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_staff_cannot_upload_image(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();

        $this->uploadFakeImage($this->tenantA, $staff, $this->productA, 800, 800)
            ->assertStatus(403);
    }

    public function test_user_of_tenant_a_cannot_upload_to_product_of_tenant_b(): void
    {
        $productB = Product::factory()->forTenant($this->tenantB)->create();

        $response = $this->uploadFakeImage($this->tenantA, $this->ownerA, $productB, 800, 800);

        // Route binding may return 404 (BelongsToTenant scope hides the resource)
        // or 403 (policy denies access). Either is correct cross-tenant isolation.
        $this->assertContains($response->status(), [403, 404]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Upload a fake image with the given dimensions and return the response.
     */
    private function uploadFakeImage(
        Tenant $tenant,
        User $user,
        Product $product,
        int $width,
        int $height,
    ): TestResponse {
        $file = UploadedFile::fake()->image('photo.jpg', $width, $height);

        return $this->actingAs($user)
            ->call(
                'POST',
                $this->tenantUrl($tenant, "/api/v1/products/{$product->id}/images"),
                [],
                [],
                ['image' => $file],
                ['HTTP_ACCEPT' => 'application/json'],
            );
    }

    /**
     * Perform a DELETE request with a JSON body (the standard deleteJson() in
     * Laravel does not attach a request body).
     *
     * @param  array<string, mixed>  $data
     */
    private function tenantDeleteJsonWithData(
        Tenant $tenant,
        User $user,
        string $uri,
        array $data,
    ): TestResponse {
        return $this->actingAs($user)
            ->json('DELETE', $this->tenantUrl($tenant, $uri), $data);
    }

    /**
     * Strip the storage base URL from a full image URL to get the disk-relative path.
     */
    private function pathOnDisk(string $url): string
    {
        $disk = config('catalog.image_disk');
        $base = Storage::disk($disk)->url('');

        return ltrim(str_replace($base, '', $url), '/');
    }
}
