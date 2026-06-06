<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class ProductApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        $this->tenantA = Tenant::factory()->create(['slug' => 'prod-api-tenant-a']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'prod-api-tenant-b']);
        $this->ownerA = User::factory()->forTenant($this->tenantA, role: 'owner')->create();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson($this->tenantUrl($this->tenantA, '/api/v1/products'))
            ->assertStatus(401);
    }

    public function test_owner_can_list_products(): void
    {
        Product::factory()->forTenant($this->tenantA)->count(3)->create();

        $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'base_price_cents', 'is_active', 'is_featured']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id'],
            ]);
    }

    public function test_list_only_returns_products_for_current_tenant(): void
    {
        Product::factory()->forTenant($this->tenantA)->count(2)->create();
        Product::factory()->forTenant($this->tenantB)->count(5)->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/products')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_supports_search_by_name(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'name' => 'Rosa Eterna',
            'slug' => 'rosa-eterna-test',
        ]);
        Product::factory()->forTenant($this->tenantA)->create([
            'name' => 'Peluche Osito',
            'slug' => 'peluche-osito-test',
        ]);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/products?search=rosa')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Rosa Eterna', $response->json('data.0.name'));
    }

    public function test_list_supports_is_active_filter(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'active-product',
            'is_active' => true,
        ]);
        Product::factory()->forTenant($this->tenantA)->inactive()->create([
            'slug' => 'inactive-product',
        ]);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/products?is_active=false')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.is_active'));
    }

    public function test_list_supports_is_featured_filter(): void
    {
        Product::factory()->forTenant($this->tenantA)->featured()->create([
            'slug' => 'featured-product',
        ]);
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'regular-product',
        ]);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/products?is_featured=true')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('data.0.is_featured'));
    }

    public function test_list_supports_category_filter(): void
    {
        $category = Category::factory()->forTenant($this->tenantA)->create(['slug' => 'cat-for-filter']);
        $productIn = Product::factory()->forTenant($this->tenantA)->create(['slug' => 'in-category']);
        $productOut = Product::factory()->forTenant($this->tenantA)->create(['slug' => 'not-in-category']);

        $productIn->categories()->attach($category->id);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, "/api/v1/products?category_id={$category->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($productIn->id, $response->json('data.0.id'));
    }

    public function test_owner_can_show_product_with_relations(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'show-product-test',
        ]);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, "/api/v1/products/{$product->id}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame($product->id, $data['id']);
        $this->assertArrayHasKey('variants', $data);
        $this->assertArrayHasKey('options', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('tags', $data);
    }

    public function test_user_of_tenant_a_cannot_access_product_of_tenant_b(): void
    {
        $productB = Product::factory()->forTenant($this->tenantB)->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, "/api/v1/products/{$productB->id}");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_owner_can_create_simple_product(): void
    {
        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/products', [
            'name' => 'Rosa Eterna Carmesi',
            'base_price_cents' => 6500,
            'is_active' => true,
        ])->assertStatus(201);

        $data = $response->json('data');
        $this->assertSame('Rosa Eterna Carmesi', $data['name']);
        $this->assertSame('rosa-eterna-carmesi', $data['slug']);
        $this->assertSame(6500, $data['base_price_cents']);

        $this->assertDatabaseHas('products', [
            'name' => 'Rosa Eterna Carmesi',
            'tenant_id' => $this->tenantA->id,
        ]);
    }

    public function test_owner_can_create_product_with_options_and_generates_variant_matrix(): void
    {
        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/products', [
            'name' => 'Rosa Bicolor',
            'base_price_cents' => 5000,
            'sku_root' => 'ROSA-BC',
            'options' => [
                ['name' => 'Color', 'values' => ['Rojo', 'Verde']],
                ['name' => 'Tamano', 'values' => ['S', 'M']],
            ],
        ])->assertStatus(201);

        $data = $response->json('data');
        $this->assertCount(4, $data['variants']);

        // All variant SKUs should be auto-generated from sku_root
        $skus = array_column($data['variants'], 'sku');
        $this->assertContains('ROSA-BC-rojo-s', $skus);
        $this->assertContains('ROSA-BC-rojo-m', $skus);
        $this->assertContains('ROSA-BC-verde-s', $skus);
        $this->assertContains('ROSA-BC-verde-m', $skus);
    }

    public function test_create_product_attaches_categories_and_tags(): void
    {
        $category = Category::factory()->forTenant($this->tenantA)->create(['slug' => 'cat-attach-test']);
        $tag = Tag::factory()->forTenant($this->tenantA)->create(['name' => 'nuevo', 'slug' => 'nuevo-test']);

        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/products', [
            'name' => 'Producto con Relaciones',
            'base_price_cents' => 2000,
            'categories' => [$category->id],
            'tags' => [$tag->id],
        ])->assertStatus(201);

        $data = $response->json('data');
        $this->assertCount(1, $data['categories']);
        $this->assertCount(1, $data['tags']);
        $this->assertSame($category->id, $data['categories'][0]['id']);
        $this->assertSame($tag->id, $data['tags'][0]['id']);
    }

    public function test_create_auto_disambiguates_duplicate_slugs(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'name' => 'Rosa',
            'slug' => 'rosa',
        ]);

        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/products', [
            'name' => 'Rosa',
            'base_price_cents' => 1000,
        ])->assertStatus(201);

        $this->assertSame('rosa-2', $response->json('data.slug'));
    }

    public function test_owner_can_update_product(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'update-test',
            'base_price_cents' => 1000,
        ]);

        $response = $this->tenantPatchJson($this->tenantA, $this->ownerA, "/api/v1/products/{$product->id}", [
            'name' => 'Nombre Actualizado',
            'base_price_cents' => 1500,
        ])->assertOk();

        $this->assertSame('Nombre Actualizado', $response->json('data.name'));
        $this->assertSame(1500, $response->json('data.base_price_cents'));
    }

    public function test_owner_can_soft_delete_product(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'to-delete-product',
        ]);

        $this->tenantDeleteJson($this->tenantA, $this->ownerA, "/api/v1/products/{$product->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_soft_delete_cascades_to_variants(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'cascade-delete-test',
        ]);
        $variant = $product->variants()->create([
            'sku' => 'CASCADE-01',
            'position' => 0,
            'options' => [],
        ]);

        $this->tenantDeleteJson($this->tenantA, $this->ownerA, "/api/v1/products/{$product->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }

    public function test_owner_can_restore_soft_deleted_product(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'to-restore-product',
        ]);
        $product->delete();

        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, "/api/v1/products/{$product->id}/restore")
            ->assertOk();

        $this->assertSame($product->id, $response->json('data.id'));
        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_staff_cannot_create_product(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();

        $this->tenantPostJson($this->tenantA, $staff, '/api/v1/products', [
            'name' => 'Staff Product',
            'base_price_cents' => 1000,
        ])->assertStatus(403);
    }

    public function test_staff_can_list_products(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();
        Product::factory()->forTenant($this->tenantA)->create(['slug' => 'staff-visible']);

        $this->tenantGetJson($this->tenantA, $staff, '/api/v1/products')
            ->assertOk();
    }

    public function test_create_rejects_missing_required_fields(): void
    {
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/products', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('name')
            ->assertJsonValidationErrorFor('base_price_cents');
    }

    public function test_create_rejects_invalid_slug_format(): void
    {
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/products', [
            'name' => 'Test',
            'slug' => 'Invalid Slug!',
            'base_price_cents' => 1000,
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }

    public function test_owner_can_add_variant_to_product(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'add-variant-test',
        ]);

        $response = $this->tenantPostJson(
            $this->tenantA,
            $this->ownerA,
            "/api/v1/products/{$product->id}/variants",
            [
                'sku' => 'TEST-SKU-01',
                'price_cents' => 2500,
                'options' => ['Color' => 'Azul'],
            ]
        )->assertStatus(201);

        $this->assertSame('TEST-SKU-01', $response->json('data.sku'));
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-01',
        ]);
    }

    public function test_owner_can_update_variant(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'update-variant-test',
        ]);
        $variant = $product->variants()->create([
            'sku' => 'ORIGINAL-SKU',
            'position' => 0,
            'options' => [],
        ]);

        $response = $this->tenantPatchJson(
            $this->tenantA,
            $this->ownerA,
            "/api/v1/products/{$product->id}/variants/{$variant->id}",
            ['price_cents' => 9900]
        )->assertOk();

        $this->assertSame(9900, $response->json('data.price_cents'));
    }

    public function test_owner_can_soft_delete_variant(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'delete-variant-test',
        ]);
        $variant = $product->variants()->create([
            'sku' => 'DELETE-ME',
            'position' => 0,
            'options' => [],
        ]);

        $this->tenantDeleteJson(
            $this->tenantA,
            $this->ownerA,
            "/api/v1/products/{$product->id}/variants/{$variant->id}"
        )->assertStatus(204);

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }

    public function test_owner_can_reorder_variants(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'reorder-variants-test',
        ]);
        $variantA = $product->variants()->create(['sku' => 'REORDER-A', 'position' => 0, 'options' => []]);
        $variantB = $product->variants()->create(['sku' => 'REORDER-B', 'position' => 1, 'options' => []]);

        $this->tenantPatchJson(
            $this->tenantA,
            $this->ownerA,
            "/api/v1/products/{$product->id}/variants/reorder",
            [
                'items' => [
                    ['id' => $variantA->id, 'position' => 10],
                    ['id' => $variantB->id, 'position' => 0],
                ],
            ]
        )->assertStatus(204);

        $this->assertDatabaseHas('product_variants', ['id' => $variantA->id, 'position' => 10]);
        $this->assertDatabaseHas('product_variants', ['id' => $variantB->id, 'position' => 0]);
    }
}
