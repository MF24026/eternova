<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepositoryInterface
{
    /**
     * Return a paginated list of products scoped to the current tenant.
     *
     * Supported filters:
     *   - search:      matches name, sku_root, or a variant sku (LIKE %term%)
     *   - category_id: join on category_product
     *   - tag_id:      join on product_tag
     *   - is_active:   boolean filter
     *   - is_featured: boolean filter
     *   - with:        comma-separated relations to eager-load (variants, options, tags, categories)
     *   - per_page:    page size, defaults 20, max 100
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Find a product by id within the current tenant scope, loading the full
     * relation tree: variants, options.values, categories, tags.
     */
    public function findWithRelations(int $id): ?Product;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product;

    /**
     * Soft-delete a product. Cascades to its variants (also soft-deleted).
     */
    public function delete(Product $product): void;

    /**
     * Restore a soft-deleted product and all of its variants.
     */
    public function restore(Product $product): void;

    /**
     * Return true when a product with the given slug exists (trashed or not)
     * in the current tenant. Used to enforce unique slugs.
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool;

    /**
     * Return true when a product with this id belongs to the current tenant.
     */
    public function existsForCurrentTenant(int $id): bool;
}
