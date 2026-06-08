<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentProductRepository implements ProductRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = Product::query();

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = (string) $filters['search'];
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('products.name', 'like', "%{$term}%")
                    ->orWhere('products.sku_root', 'like', "%{$term}%")
                    ->orWhereExists(static function ($sub) use ($term): void {
                        $sub->from('product_variants')
                            ->whereColumn('product_variants.product_id', 'products.id')
                            ->where('product_variants.sku', 'like', "%{$term}%")
                            ->whereNull('product_variants.deleted_at');
                    });
            });
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== null) {
            $categoryId = (int) $filters['category_id'];
            $query->whereExists(static function ($sub) use ($categoryId): void {
                $sub->from('category_product')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->where('category_product.category_id', $categoryId);
            });
        }

        if (isset($filters['tag_id']) && $filters['tag_id'] !== null) {
            $tagId = (int) $filters['tag_id'];
            $query->whereExists(static function ($sub) use ($tagId): void {
                $sub->from('product_tag')
                    ->whereColumn('product_tag.product_id', 'products.id')
                    ->where('product_tag.tag_id', $tagId);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (array_key_exists('is_featured', $filters) && $filters['is_featured'] !== null) {
            $query->where('is_featured', (bool) $filters['is_featured']);
        }

        $this->applyEagerLoads($query, $filters['with'] ?? null);

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function findWithRelations(int $id): ?Product
    {
        return Product::with([
            'variants' => static function ($q): void {
                $q->orderBy('position');
            },
            'options.values',
            'categories',
            'tags',
        ])->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        return Product::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->fresh() ?? $product;
    }

    /**
     * Soft-delete the product, then cascade to its variants so stock/order
     * references still have a valid record but variants no longer appear in queries.
     */
    public function delete(Product $product): void
    {
        ProductVariant::where('product_id', $product->id)->delete();
        $product->delete();
    }

    /**
     * Restore the product and all of its variants together.
     */
    public function restore(Product $product): void
    {
        $product->restore();
        ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->restore();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Product::withTrashed()->where('slug', $slug);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function existsForCurrentTenant(int $id): bool
    {
        return Product::where('id', $id)->exists();
    }

    /**
     * Apply eager loads from the comma-separated ?with= query param.
     * Only whitelisted relations are allowed to prevent N+1 exploitation.
     *
     * @param  Builder<Product>  $query
     */
    private function applyEagerLoads(Builder $query, mixed $withParam): void
    {
        if (! is_string($withParam) || $withParam === '') {
            return;
        }

        $allowed = ['variants', 'options', 'tags', 'categories'];
        $requested = array_map('trim', explode(',', $withParam));
        $relations = array_intersect($requested, $allowed);

        if (in_array('options', $relations, strict: true)) {
            $key = array_search('options', $relations, strict: true);
            if ($key !== false) {
                unset($relations[$key]);
            }
            $query->with('options.values');
        }

        foreach ($relations as $relation) {
            $query->with($relation);
        }
    }
}
