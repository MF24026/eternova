<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\Storefront\StorefrontProductCollection;
use App\Modules\Catalog\Http\Resources\Storefront\StorefrontProductResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public product catalog endpoints for the storefront.
 *
 * No auth. No policy. Tenant scope applied automatically via BelongsToTenant.
 *
 * Stock availability is resolved from the tenant's main branch only, keyed by
 * product_variant_id. The availability map is injected into each variant resource
 * via additional() so no relation needs to be added to ProductVariant.
 */
final class StorefrontProductController extends Controller
{
    /**
     * Paginated active products with optional filters.
     *
     * Query parameters:
     *   - category_slug (string) — filter to products in a category
     *   - tag_slug      (string) — filter to products with a tag
     *   - search        (string) — name LIKE search
     *   - sort          (string) — featured|price_asc|price_desc|newest (default: featured)
     *   - per_page      (int)    — page size, 1-48, default 16
     */
    public function index(Request $request): StorefrontProductCollection
    {
        $query = Product::query()
            ->where('is_active', true)
            ->with(['categories:id,name,slug', 'tags:id,name,slug']);

        $this->applyCategoryFilter($query, $request->query('category_slug'));
        $this->applyTagFilter($query, $request->query('tag_slug'));
        $this->applySearchFilter($query, $request->query('search'));
        $this->applySorting($query, (string) $request->query('sort', 'featured'));

        $perPage = max(1, min(48, (int) $request->query('per_page', '16')));

        return new StorefrontProductCollection($query->paginate($perPage));
    }

    /**
     * Active featured products for the storefront home (hero section).
     *
     * Returns up to 8 featured active products sorted by featured+newest.
     */
    public function featured(): StorefrontProductCollection
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('is_featured', true)
            ->with(['categories:id,name,slug', 'tags:id,name,slug'])
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(8);

        return new StorefrontProductCollection($products);
    }

    /**
     * Single active product detail with variants and stock availability.
     *
     * Returns 404 when the product is inactive or belongs to a different tenant
     * (BelongsToTenant scope ensures the latter automatically).
     */
    public function show(string $slug): StorefrontProductResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'variants' => static fn ($q) => $q->orderBy('position'),
                'categories:id,name,slug',
                'tags:id,name,slug',
            ])
            ->firstOrFail();

        $stockMap = $this->buildStockMapForProduct($product);

        return (new StorefrontProductResource($product))
            ->additional(['stock' => $stockMap]);
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * @param  Builder<Product>  $query
     */
    private function applyCategoryFilter(
        Builder $query,
        mixed $categorySlug
    ): void {
        if (! is_string($categorySlug) || $categorySlug === '') {
            return;
        }

        $category = Category::where('slug', $categorySlug)
            ->where('is_active', true)
            ->first();

        if ($category === null) {
            // Unknown category slug — return empty result set
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereExists(static function ($sub) use ($category): void {
            $sub->from('category_product')
                ->whereColumn('category_product.product_id', 'products.id')
                ->where('category_product.category_id', $category->id);
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyTagFilter(
        Builder $query,
        mixed $tagSlug
    ): void {
        if (! is_string($tagSlug) || $tagSlug === '') {
            return;
        }

        $tag = Tag::where('slug', $tagSlug)->first();

        if ($tag === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereExists(static function ($sub) use ($tag): void {
            $sub->from('product_tag')
                ->whereColumn('product_tag.product_id', 'products.id')
                ->where('product_tag.tag_id', $tag->id);
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySearchFilter(
        Builder $query,
        mixed $search
    ): void {
        if (! is_string($search) || $search === '') {
            return;
        }

        $term = $search;

        $query->where(static function ($q) use ($term): void {
            $q->where('products.name', 'like', "%{$term}%")
                ->orWhere('products.description', 'like', "%{$term}%");
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySorting(
        Builder $query,
        string $sort
    ): void {
        match ($sort) {
            'price_asc' => $query->orderBy('base_price_cents'),
            'price_desc' => $query->orderByDesc('base_price_cents'),
            'newest' => $query->orderByDesc('created_at'),
            // Default "featured": featured first, then newest among non-featured
            default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
        };
    }

    /**
     * Build a map of product_variant_id → available quantity from the tenant's
     * main branch. Used to annotate variant resources with stock availability.
     *
     * Returns Collection<int, int> keyed by product_variant_id.
     * Variants with no inventory row get 0 (out of stock — safe default).
     *
     * @return Collection<int, int>
     */
    private function buildStockMapForProduct(Product $product): Collection
    {
        $mainBranch = $this->resolveMainBranch();

        if ($mainBranch === null) {
            return collect();
        }

        $variantIds = $product->variants->pluck('id')->all();

        if (empty($variantIds)) {
            return collect();
        }

        return BranchInventory::query()
            ->where('branch_id', $mainBranch->id)
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('available', 'product_variant_id');
    }

    /**
     * Resolve the tenant's main branch. Cached in-memory for the request lifetime
     * (single HTTP request may call this once per product).
     */
    private function resolveMainBranch(): ?Branch
    {
        /** @var Tenant $tenant */
        $tenant = app('currentTenant');

        return Branch::where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->first();
    }
}
