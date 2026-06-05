<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    private function setTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public function test_product_has_many_variants(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->forTenant($tenant)->create();

        ProductVariant::factory()->forProduct($product)->count(3)->create();

        $this->assertCount(3, $product->variants);
    }

    public function test_product_has_many_options_in_position_order(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->forTenant($tenant)->create();

        // Create in scrambled position order
        ProductOption::factory()->forProduct($product)->create(['name' => 'Color',   'position' => 2]);
        ProductOption::factory()->forProduct($product)->create(['name' => 'Material', 'position' => 0]);
        ProductOption::factory()->forProduct($product)->create(['name' => 'Tamano',  'position' => 1]);

        $options = $product->options;

        $this->assertCount(3, $options);
        $this->assertSame(0, $options->get(0)->position);
        $this->assertSame(1, $options->get(1)->position);
        $this->assertSame(2, $options->get(2)->position);
    }

    public function test_product_belongs_to_many_categories_through_pivot(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setTenant($tenant);

        $product = Product::factory()->forTenant($tenant)->create();
        $categoryA = Category::factory()->forTenant($tenant)->create();
        $categoryB = Category::factory()->forTenant($tenant)->create();

        $product->categories()->attach([
            $categoryA->id => ['sort_order' => 1],
            $categoryB->id => ['sort_order' => 2],
        ]);

        $categories = $product->categories;
        $this->assertCount(2, $categories);

        // Verify pivot sort_order is accessible
        $attached = $categories->firstWhere('id', $categoryA->id);
        $this->assertNotNull($attached);
        $this->assertSame(1, $attached->pivot->sort_order);

        // Verify pivot tenant_id was auto-set by CategoryProduct::creating()
        $this->assertDatabaseHas('category_product', [
            'product_id' => $product->id,
            'category_id' => $categoryA->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_product_belongs_to_many_tags(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->forTenant($tenant)->create();
        $tagA = Tag::factory()->forTenant($tenant)->create();
        $tagB = Tag::factory()->forTenant($tenant)->create();

        $product->tags()->attach([$tagA->id, $tagB->id]);

        $this->assertCount(2, $product->tags);
        $this->assertTrue($product->tags->contains('id', $tagA->id));
        $this->assertTrue($product->tags->contains('id', $tagB->id));
    }
}
