<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Repositories\ProductRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates Product creation and updates.
 *
 * Flow for ProductService::create():
 *   1. Begin DB transaction
 *   2. Resolve unique slug from name (same algorithm as CategoryService)
 *   3. Persist Product row (BelongsToTenant injects tenant_id automatically)
 *   4. If options[] provided:  create ProductOption rows + ProductOptionValue rows,
 *      then delegate to ProductVariantService::generateMatrix() for cartesian product
 *   5. If variants[] provided: delegate to ProductVariantService::createExplicitVariants()
 *      (options and variants are mutually exclusive; explicit variants win when both present)
 *   6. Attach categories via M2M (scope ensures only same-tenant categories are attached)
 *   7. Attach tags via M2M
 *   8. Commit transaction
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductVariantService $variantService,
    ) {}

    /**
     * Create a product atomically: slug, variants/options, categories, tags — all or nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $data['slug'] = $this->resolveUniqueSlug(
                $data['slug'] ?? '',
                (string) $data['name'],
            );

            $productData = $this->extractProductFields($data);
            $product = $this->products->create($productData);

            $this->attachOptionsAndVariants($product, $data);
            $this->syncCategories($product, $data['categories'] ?? []);
            $this->syncTags($product, $data['tags'] ?? []);

            Log::info('Product created', [
                'product_id' => $product->id,
                'slug' => $product->slug,
                'tenant_id' => $product->tenant_id,
                'base_price_cents' => $product->base_price_cents,
            ]);

            return $product;
        });
    }

    /**
     * Update a product and optionally replace its relations.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            if (isset($data['slug']) && $data['slug'] !== '' && $data['slug'] !== $product->slug) {
                $data['slug'] = $this->resolveUniqueSlug($data['slug'], $data['slug'], $product->id);
            } elseif (! isset($data['slug']) || $data['slug'] === '') {
                unset($data['slug']);
            }

            $productData = $this->extractProductFields($data);
            $updated = $this->products->update($product, $productData);

            if (array_key_exists('categories', $data)) {
                $this->syncCategories($updated, $data['categories']);
            }

            if (array_key_exists('tags', $data)) {
                $this->syncTags($updated, $data['tags']);
            }

            Log::info('Product updated', [
                'product_id' => $updated->id,
                'tenant_id' => $updated->tenant_id,
            ]);

            return $updated;
        });
    }

    /**
     * Soft-delete a product and cascade to its variants.
     */
    public function delete(Product $product): void
    {
        $this->products->delete($product);

        Log::info('Product soft-deleted', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
        ]);
    }

    /**
     * Restore a soft-deleted product and its variants.
     */
    public function restore(Product $product): void
    {
        $this->products->restore($product);

        Log::info('Product restored', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
        ]);
    }

    /**
     * Build and persist options + variants.
     *
     * Rule: if both options and variants are in the payload, explicit variants win
     * (the caller has customised the matrix). Options rows are still created for
     * display purposes, but the variant matrix is NOT auto-generated.
     *
     * @param  array<string, mixed>  $data
     */
    private function attachOptionsAndVariants(Product $product, array $data): void
    {
        $hasOptions = isset($data['options']) && is_array($data['options']) && $data['options'] !== [];
        $hasVariants = isset($data['variants']) && is_array($data['variants']) && $data['variants'] !== [];

        if ($hasOptions) {
            $this->createOptions($product, $data['options']);
        }

        if ($hasVariants) {
            // Explicit variants from the caller — skip matrix generation.
            $this->variantService->createExplicitVariants($product, $data['variants']);
        } elseif ($hasOptions) {
            // Auto-generate from the cartesian product.
            $this->variantService->generateMatrix(
                $product,
                $data['options'],
                $product->base_price_cents,
            );
        }
    }

    /**
     * Persist ProductOption + ProductOptionValue rows for the product.
     *
     * @param  array<int, array{name: string, values: list<string>}>  $options
     */
    private function createOptions(Product $product, array $options): void
    {
        foreach ($options as $position => $optionData) {
            $option = ProductOption::create([
                'product_id' => $product->id,
                'name' => $optionData['name'],
                'position' => $position,
            ]);

            foreach ($optionData['values'] as $valuePosition => $value) {
                $option->values()->create([
                    'option_id' => $option->id,
                    'value' => $value,
                    'position' => $valuePosition,
                ]);
            }
        }
    }

    /**
     * Sync M2M categories. BelongsToTenant scope on the Category model ensures
     * only same-tenant categories are accessible — any cross-tenant id will simply
     * not be found and will be silently skipped by attach().
     *
     * @param  list<int>  $categoryIds
     */
    private function syncCategories(Product $product, array $categoryIds): void
    {
        $product->categories()->sync($categoryIds);
    }

    /**
     * Sync M2M tags. Same tenant-isolation guarantee as categories.
     *
     * @param  list<int>  $tagIds
     */
    private function syncTags(Product $product, array $tagIds): void
    {
        $product->tags()->sync($tagIds);
    }

    /**
     * Extract only the fields that belong on the products table row.
     * Strips out relation payloads (options, variants, categories, tags).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractProductFields(array $data): array
    {
        $relationsKeys = ['options', 'variants', 'categories', 'tags'];

        return array_diff_key($data, array_flip($relationsKeys));
    }

    /**
     * Resolve a unique slug for a product within the current tenant.
     * Mirrors CategoryService::resolveUniqueSlug() to keep the algorithm consistent.
     */
    private function resolveUniqueSlug(string $requestedSlug, string $name, ?int $excludeId = null): string
    {
        $base = $requestedSlug !== '' ? $requestedSlug : Str::slug($name);

        if (! $this->products->slugExists($base, $excludeId)) {
            return $base;
        }

        for ($i = 2; $i <= 100; $i++) {
            $candidate = "{$base}-{$i}";

            if (! $this->products->slugExists($candidate, $excludeId)) {
                return $candidate;
            }
        }

        throw new DomainException(
            "Could not generate a unique slug for \"{$name}\" after 100 attempts."
        );
    }
}
