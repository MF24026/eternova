<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentCategoryRepository implements CategoryRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Category>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(1, min(100, $perPage));

        $query = Category::query()
            ->withCount('products');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = (string) $filters['search'];
            $query->where(static function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (array_key_exists('parent_id', $filters) && $filters['parent_id'] !== null) {
            $query->where('parent_id', (int) $filters['parent_id']);
        }

        if (isset($filters['include_children']) && $filters['include_children'] === true) {
            $query->with(['children' => static function ($q): void {
                $q->withCount('products')->orderBy('sort_order');
            }]);
        }

        return $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);
    }

    public function findWithRelations(int $id): ?Category
    {
        return Category::withCount('products')
            ->with([
                'parent',
                'children' => static function ($q): void {
                    $q->withCount('products')->orderBy('sort_order');
                },
            ])
            ->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category
    {
        return Category::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        $category->update($data);

        return $category->fresh() ?? $category;
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }

    public function restore(Category $category): void
    {
        $category->restore();
    }

    /**
     * @param  array<int, array{id: int, sort_order: int, parent_id: int|null}>  $items
     */
    public function bulkReorder(array $items): void
    {
        DB::transaction(static function () use ($items): void {
            foreach ($items as $item) {
                Category::withoutGlobalScopes()
                    ->where('id', $item['id'])
                    ->update([
                        'sort_order' => $item['sort_order'],
                        'parent_id' => $item['parent_id'],
                    ]);
            }
        });
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Category::withTrashed()->where('slug', $slug);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function existsForCurrentTenant(int $id): bool
    {
        return Category::where('id', $id)->exists();
    }
}
