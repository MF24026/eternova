<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentTagRepository implements TagRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Tag>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = Tag::query();

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = (string) $filters['search'];
            $query->where(static function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * @return list<Tag>
     */
    public function all(): array
    {
        return Tag::orderBy('name')->get()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tag
    {
        return Tag::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tag $tag, array $data): Tag
    {
        $tag->update($data);

        return $tag->fresh() ?? $tag;
    }

    public function delete(Tag $tag): void
    {
        $tag->delete();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Tag::where('slug', $slug);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function existsForCurrentTenant(int $id): bool
    {
        return Tag::where('id', $id)->exists();
    }
}
