<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class CategoryScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function setTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    private function clearTenant(): void
    {
        app()->bind('currentTenant', fn (): ?Tenant => null);
    }

    public function test_belongs_to_tenant_scope_filters_categories_to_current_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $catA1 = Category::factory()->forTenant($tenantA)->create();
        $catA2 = Category::factory()->forTenant($tenantA)->create();
        $catB1 = Category::factory()->forTenant($tenantB)->create();

        $this->setTenant($tenantA);

        $results = Category::all();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $catA1->id));
        $this->assertTrue($results->contains('id', $catA2->id));
        $this->assertFalse($results->contains('id', $catB1->id));
    }

    public function test_creating_category_without_tenant_throws_outside_tenant_context(): void
    {
        $this->clearTenant();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/requires a resolved tenant/');

        Category::create([
            'name' => 'Sin Tenant',
            'slug' => 'sin-tenant',
        ]);
    }
}
