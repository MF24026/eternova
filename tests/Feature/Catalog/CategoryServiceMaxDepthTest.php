<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryService;
use App\Modules\Tenancy\Models\Tenant;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryServiceMaxDepthTest extends TestCase
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

    public function test_can_create_root_category(): void
    {
        $category = $this->service->create(['name' => 'Root Category', 'slug' => 'root-cat']);

        $this->assertSame(0, $category->depth());
        $this->assertNull($category->parent_id);
    }

    public function test_can_create_child_category_under_root(): void
    {
        $root = Category::factory()->forTenant($this->tenant)->create(['slug' => 'root']);

        $child = $this->service->create([
            'name' => 'Child',
            'parent_id' => $root->id,
        ]);

        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame(1, $child->depth());
    }

    public function test_cannot_create_category_under_a_child_exceeding_depth_2(): void
    {
        $root = Category::factory()->forTenant($this->tenant)->create(['slug' => 'root-d2']);
        $child = Category::factory()
            ->forTenant($this->tenant)
            ->withParent($root)
            ->create(['slug' => 'child-d2']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/maximum nesting depth/');

        $this->service->create([
            'name' => 'Grandchild',
            'parent_id' => $child->id,
        ]);
    }

    public function test_cannot_move_category_under_a_grandchild_via_update(): void
    {
        $root = Category::factory()->forTenant($this->tenant)->create(['slug' => 'root-mv']);
        $child = Category::factory()
            ->forTenant($this->tenant)
            ->withParent($root)
            ->create(['slug' => 'child-mv']);

        $floatingRoot = Category::factory()->forTenant($this->tenant)->create(['slug' => 'floating']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/maximum nesting depth/');

        // child already has a parent (root), so moving floatingRoot under child would
        // make floatingRoot a grandchild → depth 2+
        $this->service->update($floatingRoot, ['parent_id' => $child->id]);
    }

    public function test_cannot_make_category_its_own_parent(): void
    {
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'self-ref']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/circular reference/');

        $this->service->update($category, ['parent_id' => $category->id]);
    }

    public function test_cannot_move_category_under_its_own_child(): void
    {
        $parent = Category::factory()->forTenant($this->tenant)->create(['slug' => 'parent-circ']);
        $child = Category::factory()
            ->forTenant($this->tenant)
            ->withParent($parent)
            ->create(['slug' => 'child-circ']);

        $this->expectException(DomainException::class);

        // Moving parent under child would make parent a child of its own child.
        // The service blocks this because child already has a parent (depth would exceed 2).
        $this->service->update($parent, ['parent_id' => $child->id]);
    }

    public function test_reorder_throws_when_exceeding_max_depth(): void
    {
        $root = Category::factory()->forTenant($this->tenant)->create(['slug' => 'root-ro']);
        $child = Category::factory()
            ->forTenant($this->tenant)
            ->withParent($root)
            ->create(['slug' => 'child-ro']);
        $floater = Category::factory()->forTenant($this->tenant)->create(['slug' => 'floater-ro']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/maximum nesting depth/');

        // Try to put floater under child (depth would be 2+)
        $this->service->reorder([
            ['id' => $floater->id, 'sort_order' => 0, 'parent_id' => $child->id],
        ]);
    }
}
