<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TagRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Tag>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Return all tags for the current tenant (no pagination).
     * Used for autocomplete selectors.
     *
     * @return list<Tag>
     */
    public function all(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tag;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tag $tag, array $data): Tag;

    public function delete(Tag $tag): void;

    public function slugExists(string $slug, ?int $excludeId = null): bool;

    public function existsForCurrentTenant(int $id): bool;
}
