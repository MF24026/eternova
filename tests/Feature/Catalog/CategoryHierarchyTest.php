<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function setTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public function test_category_can_have_subcategories(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setTenant($tenant);

        $parent = Category::factory()->forTenant($tenant)->create(['parent_id' => null]);

        $child1 = Category::factory()->forTenant($tenant)->withParent($parent)->create();
        $child2 = Category::factory()->forTenant($tenant)->withParent($parent)->create();

        $children = $parent->children;

        $this->assertCount(2, $children);
        $this->assertTrue($children->contains('id', $child1->id));
        $this->assertTrue($children->contains('id', $child2->id));
        $this->assertSame($parent->id, $child1->parent()->first()->id);
    }

    public function test_category_depth_is_calculated_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setTenant($tenant);

        $root = Category::factory()->forTenant($tenant)->create(['parent_id' => null]);
        $child = Category::factory()->forTenant($tenant)->withParent($root)->create();
        $grandchild = Category::factory()->forTenant($tenant)->withParent($child)->create();

        $this->assertSame(0, $root->depth());
        $this->assertSame(1, $child->depth());
        $this->assertSame(2, $grandchild->depth());
    }
}
