<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryServiceSlugGenerationTest extends TestCase
{
    use RefreshDatabase;

    private CategoryService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);

        $this->service = app(CategoryService::class);
    }

    public function test_slug_is_generated_from_name_when_not_provided(): void
    {
        $category = $this->service->create(['name' => 'Rosas Eternas']);

        $this->assertSame('rosas-eternas', $category->slug);
    }

    public function test_slug_handles_special_characters_in_name(): void
    {
        $category = $this->service->create(['name' => 'Arreglos & Flores']);

        $this->assertNotEmpty($category->slug);
        // Str::slug strips non-alphanumeric characters
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $category->slug);
    }

    public function test_duplicate_slug_gets_numeric_suffix(): void
    {
        Category::factory()->forTenant($this->tenant)->create(['name' => 'Flores', 'slug' => 'flores']);

        $second = $this->service->create(['name' => 'Flores']);

        $this->assertSame('flores-2', $second->slug);
    }

    public function test_third_duplicate_gets_suffix_3(): void
    {
        Category::factory()->forTenant($this->tenant)->create(['name' => 'Globos', 'slug' => 'globos']);
        Category::factory()->forTenant($this->tenant)->create(['name' => 'Globos', 'slug' => 'globos-2']);

        $third = $this->service->create(['name' => 'Globos']);

        $this->assertSame('globos-3', $third->slug);
    }

    public function test_explicit_slug_is_used_when_provided(): void
    {
        $category = $this->service->create(['name' => 'Peluches', 'slug' => 'peluches-especiales']);

        $this->assertSame('peluches-especiales', $category->slug);
    }

    public function test_explicit_duplicate_slug_gets_suffix(): void
    {
        Category::factory()->forTenant($this->tenant)->create(['slug' => 'rosas']);

        $second = $this->service->create(['name' => 'Rosas 2', 'slug' => 'rosas']);

        $this->assertSame('rosas-2', $second->slug);
    }

    public function test_duplicate_check_is_tenant_scoped(): void
    {
        $tenantB = Tenant::factory()->create();

        // "flores" exists in tenantB but NOT in tenantA (the current tenant)
        Category::factory()->forTenant($tenantB)->create(['slug' => 'flores-tenant-b']);

        // In tenantA, "flores" should be fresh — no suffix needed
        $category = $this->service->create(['name' => 'Flores', 'slug' => 'flores-tenant-b']);

        // The slug validator checks within the current tenant scope via BelongsToTenant
        // so the same slug in tenantB does NOT conflict
        $this->assertStringNotContainsString('-2', $category->slug);
    }

    public function test_update_does_not_suffix_own_unchanged_slug(): void
    {
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'existing-slug']);

        // Sending the same slug back on update should not produce "existing-slug-2"
        $updated = $this->service->update($category, ['slug' => 'existing-slug']);

        // It should keep the same slug since it's the same category
        $this->assertSame('existing-slug', $updated->slug);
    }
}
