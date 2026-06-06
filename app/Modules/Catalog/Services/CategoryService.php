<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Repositories\CategoryRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CategoryService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
    ) {}

    /**
     * Create a new category, auto-generating a unique slug from the name when
     * the caller does not supply one.
     *
     * Business rules enforced:
     *   - parent_id must belong to the current tenant.
     *   - max depth is 2 (parent may have a parent, but parent's parent must be null).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category
    {
        $data['slug'] = $this->resolveUniqueSlug(
            $data['slug'] ?? '',
            (string) $data['name'],
        );

        if (isset($data['parent_id']) && $data['parent_id'] !== null) {
            $this->assertParentIsValid((int) $data['parent_id']);
        }

        $category = $this->categories->create($data);

        Log::info('Category created', [
            'category_id' => $category->id,
            'slug' => $category->slug,
            'tenant_id' => $category->tenant_id,
            'parent_id' => $category->parent_id,
        ]);

        return $category;
    }

    /**
     * Update an existing category.
     *
     * Additional business rules when parent_id changes:
     *   - New parent must belong to the current tenant.
     *   - Max depth is still 2.
     *   - Circular reference prevention: a category cannot become its own descendant.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        if (isset($data['slug']) && $data['slug'] !== '' && $data['slug'] !== $category->slug) {
            $data['slug'] = $this->resolveUniqueSlug($data['slug'], $data['slug'], $category->id);
        } elseif (! isset($data['slug']) || $data['slug'] === '') {
            unset($data['slug']);
        }

        $newParentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id;

        if ($newParentId !== null && $newParentId !== $category->parent_id) {
            $this->assertParentIsValid((int) $newParentId, $category);
        }

        $updated = $this->categories->update($category, $data);

        Log::info('Category updated', [
            'category_id' => $updated->id,
            'slug' => $updated->slug,
            'tenant_id' => $updated->tenant_id,
        ]);

        return $updated;
    }

    /**
     * Soft-delete a category.
     *
     * Children are orphaned (parent_id set to null) so they remain accessible
     * as root-level categories rather than silently disappearing. This is a
     * deliberate product decision: bulk-deleting a tree is a separate explicit
     * operation that does not exist in v1.
     */
    public function delete(Category $category): void
    {
        // Orphan children before soft-deleting the parent so no child ends up
        // pointing to a deleted parent_id, which would break tree queries.
        Category::where('parent_id', $category->id)->update(['parent_id' => null]);

        $this->categories->delete($category);

        Log::info('Category soft-deleted', [
            'category_id' => $category->id,
            'tenant_id' => $category->tenant_id,
        ]);
    }

    /**
     * Restore a previously soft-deleted category.
     */
    public function restore(Category $category): void
    {
        $this->categories->restore($category);

        Log::info('Category restored', [
            'category_id' => $category->id,
            'tenant_id' => $category->tenant_id,
        ]);
    }

    /**
     * Bulk-update sort_order and parent_id for an ordered set of categories.
     *
     * Items not in the payload are not touched. Each item is validated for
     * tenant ownership and max-depth before the transaction begins.
     *
     * @param  array<int, array{id: int, sort_order: int, parent_id: int|null}>  $items
     */
    public function reorder(array $items): void
    {
        foreach ($items as $item) {
            if (! $this->categories->existsForCurrentTenant($item['id'])) {
                throw new DomainException(
                    "Category #{$item['id']} does not belong to the current tenant."
                );
            }

            if ($item['parent_id'] !== null) {
                $parent = Category::find($item['parent_id']);

                if ($parent === null) {
                    throw new DomainException(
                        "Parent category #{$item['parent_id']} not found."
                    );
                }

                // After this reorder, the category will be at depth 1 (child of parent).
                // The parent itself must be a root (depth 0) to keep max depth at 2.
                if ($parent->parent_id !== null) {
                    throw new DomainException(
                        "Cannot assign category #{$item['id']} under category #{$item['parent_id']}: "
                        .'that would exceed the maximum nesting depth of 2.'
                    );
                }
            }
        }

        $this->categories->bulkReorder($items);

        Log::info('Categories reordered', ['item_count' => count($items)]);
    }

    /**
     * Return a deduplicated slug for the given name within the current tenant.
     *
     * When the caller supplies an explicit slug, it is used as-is as the base.
     * When no slug is supplied, one is derived from the name via Str::slug().
     *
     * If the base slug already exists, "-2", "-3", ... are appended until a
     * free slot is found (up to a safety cap of 100 iterations).
     */
    private function resolveUniqueSlug(string $requestedSlug, string $name, ?int $excludeId = null): string
    {
        $base = $requestedSlug !== '' ? $requestedSlug : Str::slug($name);

        if (! $this->categories->slugExists($base, $excludeId)) {
            return $base;
        }

        for ($i = 2; $i <= 100; $i++) {
            $candidate = "{$base}-{$i}";

            if (! $this->categories->slugExists($candidate, $excludeId)) {
                return $candidate;
            }
        }

        // Practically unreachable, but we must not loop forever.
        throw new DomainException(
            "Could not generate a unique slug for \"{$name}\" after 100 attempts."
        );
    }

    /**
     * Assert the given parent_id is a valid, tenant-owned category that can
     * accept children without exceeding the max depth of 2.
     *
     * @throws DomainException when the parent is invalid or would create depth > 2.
     */
    private function assertParentIsValid(int $parentId, ?Category $movingCategory = null): void
    {
        if (! $this->categories->existsForCurrentTenant($parentId)) {
            throw new DomainException(
                "Parent category #{$parentId} does not exist in the current tenant."
            );
        }

        $parent = Category::find($parentId);

        if ($parent === null) {
            throw new DomainException("Parent category #{$parentId} not found.");
        }

        // Parent is already a child (depth 1) — placing another child under it
        // would create a grandchild (depth 2+), which violates the max depth rule.
        if ($parent->parent_id !== null) {
            throw new DomainException(
                "Cannot nest category under #{$parentId}: "
                .'maximum nesting depth of 2 would be exceeded.'
            );
        }

        // Circular reference check: the target parent must not be the category itself
        // or one of its current children.
        if ($movingCategory !== null) {
            if ($parentId === $movingCategory->id) {
                throw new DomainException(
                    'A category cannot be its own parent (circular reference).'
                );
            }

            // Check the moving category is not an ancestor of the new parent
            $isDescendant = Category::where('parent_id', $movingCategory->id)
                ->where('id', $parentId)
                ->exists();

            if ($isDescendant) {
                throw new DomainException(
                    "Cannot move category #{$movingCategory->id} under #{$parentId}: "
                    .'circular reference — the target parent is a child of the moving category.'
                );
            }
        }
    }
}
