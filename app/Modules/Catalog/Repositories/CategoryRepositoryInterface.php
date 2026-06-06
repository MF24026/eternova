<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CategoryRepositoryInterface
{
    /**
     * Return a paginated list of categories scoped to the current tenant.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Category>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Find a category by id within the current tenant scope.
     * Loads children and products_count eagerly to avoid N+1 on the show endpoint.
     */
    public function findWithRelations(int $id): ?Category;

    /**
     * Persist a new category row.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category;

    /**
     * Persist changes to an existing category.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category;

    /**
     * Soft-delete a category.
     */
    public function delete(Category $category): void;

    /**
     * Restore a soft-deleted category.
     */
    public function restore(Category $category): void;

    /**
     * Bulk-update sort_order and parent_id for an ordered set of items.
     *
     * Items not in the payload are intentionally left untouched.
     *
     * @param  array<int, array{id: int, sort_order: int, parent_id: int|null}>  $items
     */
    public function bulkReorder(array $items): void;

    /**
     * Return true when any non-deleted category in the current tenant has this slug.
     * Optionally exclude a specific category id (for update uniqueness checks).
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool;

    /**
     * Return true when any non-deleted category with the given id belongs to the
     * current tenant. Used to validate parent_id references.
     */
    public function existsForCurrentTenant(int $id): bool;
}
