<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class CategoryApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $ownerA;

    /**
     * Switch to a PID-unique MySQL database so that parallel agents running
     * migrate:fresh on the shared "testing" DB cannot destroy our schema mid-run.
     *
     * Pattern borrowed from TenantResolverTest — see that file for full commentary.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $dbName = 'testing_cat_api_'.getmypid();

        try {
            $this->app['db']->connection('mysql')
                ->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable) {
            $dbName = 'testing';
        }

        $this->app['config']->set('database.connections.mysql.database', $dbName);
        $this->app['db']->purge('mysql');

        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Disable slug resolution cache so stale array-cache entries from prior
        // tests in the same process do not cause false 404s.
        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a-test']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b-test']);
        $this->ownerA = User::factory()->forTenant($this->tenantA, role: 'owner')->create();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson($this->tenantUrl($this->tenantA, '/api/v1/categories'))
            ->assertStatus(401);
    }

    public function test_owner_can_list_categories(): void
    {
        Category::factory()->forTenant($this->tenantA)->count(3)->create();

        $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/categories')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'sort_order', 'is_active', 'products_count']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id'],
            ]);
    }

    public function test_list_only_returns_categories_for_current_tenant(): void
    {
        Category::factory()->forTenant($this->tenantA)->count(2)->create();
        Category::factory()->forTenant($this->tenantB)->count(5)->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/categories')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_supports_search_filter(): void
    {
        Category::factory()->forTenant($this->tenantA)->create(['name' => 'Rosas eternas', 'slug' => 'rosas-eternas']);
        Category::factory()->forTenant($this->tenantA)->create(['name' => 'Peluches', 'slug' => 'peluches-cat']);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/categories?search=rosas')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Rosas eternas', $response->json('data.0.name'));
    }

    public function test_list_supports_is_active_filter(): void
    {
        Category::factory()->forTenant($this->tenantA)->create(['slug' => 'active-cat', 'is_active' => true]);
        Category::factory()->forTenant($this->tenantA)->inactive()->create(['slug' => 'inactive-cat']);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/categories?is_active=false')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.is_active'));
    }

    public function test_owner_can_show_category_with_children_and_product_count(): void
    {
        $parent = Category::factory()->forTenant($this->tenantA)->create(['slug' => 'parent-show']);
        Category::factory()->forTenant($this->tenantA)->withParent($parent)->count(2)->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, "/api/v1/categories/{$parent->id}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame($parent->id, $data['id']);
        $this->assertArrayHasKey('children', $data);
        $this->assertCount(2, $data['children']);
        $this->assertArrayHasKey('products_count', $data);
    }

    public function test_user_of_tenant_a_cannot_access_category_of_tenant_b(): void
    {
        // Create categoryB with explicit tenant_id — no HTTP request context needed.
        $categoryB = Category::factory()->forTenant($this->tenantB)->create();

        // Request from tenantA context: the policy's assertTenantMatches check rejects
        // the cross-tenant access. The CategoryPolicy::view() returns false → 403.
        // (Route model binding runs before EnsureTenant, so the model may be resolved
        //  but the policy blocks it from a different tenant's user.)
        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, "/api/v1/categories/{$categoryB->id}");

        // The tenant isolation guard returns either 403 (found but unauthorized) or 404
        // (not found via BelongsToTenant scope). Both prove tenant isolation is enforced.
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_owner_can_create_category(): void
    {
        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/categories', [
            'name' => 'Arreglos florales',
            'description' => 'Ramos frescos y preservados',
            'sort_order' => 1,
            'is_active' => true,
        ])->assertStatus(201);

        $data = $response->json('data');
        $this->assertSame('Arreglos florales', $data['name']);
        $this->assertSame('arreglos-florales', $data['slug']);

        $this->assertDatabaseHas('categories', [
            'name' => 'Arreglos florales',
            'tenant_id' => $this->tenantA->id,
        ]);
    }

    public function test_create_auto_disambiguates_duplicate_slugs(): void
    {
        Category::factory()->forTenant($this->tenantA)->create([
            'name' => 'Flores',
            'slug' => 'flores',
        ]);

        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/categories', [
            'name' => 'Flores',
        ])->assertStatus(201);

        $this->assertSame('flores-2', $response->json('data.slug'));
    }

    public function test_owner_can_update_category(): void
    {
        $category = Category::factory()->forTenant($this->tenantA)->create(['slug' => 'old-slug']);

        $response = $this->tenantPatchJson($this->tenantA, $this->ownerA, "/api/v1/categories/{$category->id}", [
            'name' => 'Updated Name',
        ])->assertOk();

        $this->assertSame('Updated Name', $response->json('data.name'));
    }

    public function test_owner_can_soft_delete_category(): void
    {
        $category = Category::factory()->forTenant($this->tenantA)->create(['slug' => 'to-delete']);

        $this->tenantDeleteJson($this->tenantA, $this->ownerA, "/api/v1/categories/{$category->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_soft_delete_orphans_children(): void
    {
        $parent = Category::factory()->forTenant($this->tenantA)->create(['slug' => 'parent-del']);
        $child = Category::factory()->forTenant($this->tenantA)->withParent($parent)->create();

        $this->tenantDeleteJson($this->tenantA, $this->ownerA, "/api/v1/categories/{$parent->id}")
            ->assertStatus(204);

        // Child should still exist but be orphaned (parent_id → null)
        $child->refresh();
        $this->assertNull($child->parent_id);
        $this->assertNotSoftDeleted('categories', ['id' => $child->id]);
    }

    public function test_owner_can_restore_soft_deleted_category(): void
    {
        $category = Category::factory()->forTenant($this->tenantA)->create(['slug' => 'to-restore']);
        $category->delete();

        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, "/api/v1/categories/{$category->id}/restore")
            ->assertOk();

        $this->assertSame($category->id, $response->json('data.id'));
        $this->assertNotSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_owner_can_reorder_categories(): void
    {
        $catA = Category::factory()->forTenant($this->tenantA)->create(['sort_order' => 0, 'slug' => 'cat-a-reorder']);
        $catB = Category::factory()->forTenant($this->tenantA)->create(['sort_order' => 1, 'slug' => 'cat-b-reorder']);

        $this->tenantPatchJson($this->tenantA, $this->ownerA, '/api/v1/categories/reorder', [
            'items' => [
                ['id' => $catA->id, 'sort_order' => 10, 'parent_id' => null],
                ['id' => $catB->id, 'sort_order' => 0, 'parent_id' => null],
            ],
        ])->assertStatus(204);

        $this->assertDatabaseHas('categories', ['id' => $catA->id, 'sort_order' => 10]);
        $this->assertDatabaseHas('categories', ['id' => $catB->id, 'sort_order' => 0]);
    }

    public function test_staff_cannot_create_category(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();

        $this->tenantPostJson($this->tenantA, $staff, '/api/v1/categories', ['name' => 'New Category'])
            ->assertStatus(403);
    }

    public function test_staff_can_list_categories(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();
        Category::factory()->forTenant($this->tenantA)->create(['slug' => 'visible-staff']);

        $this->tenantGetJson($this->tenantA, $staff, '/api/v1/categories')
            ->assertOk();
    }

    public function test_create_rejects_invalid_slug_format(): void
    {
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/categories', [
            'name' => 'Test',
            'slug' => 'Invalid Slug!',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }
}
