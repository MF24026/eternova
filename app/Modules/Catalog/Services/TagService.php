<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Repositories\TagRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TagService
{
    public function __construct(
        private readonly TagRepositoryInterface $tags,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tag
    {
        $data['slug'] = $this->resolveUniqueSlug(
            $data['slug'] ?? '',
            (string) $data['name'],
        );

        $tag = $this->tags->create($data);

        Log::info('Tag created', [
            'tag_id' => $tag->id,
            'slug' => $tag->slug,
            'tenant_id' => $tag->tenant_id,
        ]);

        return $tag;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tag $tag, array $data): Tag
    {
        if (isset($data['slug']) && $data['slug'] !== '' && $data['slug'] !== $tag->slug) {
            $data['slug'] = $this->resolveUniqueSlug($data['slug'], $data['slug'], $tag->id);
        } elseif (! isset($data['slug']) || $data['slug'] === '') {
            unset($data['slug']);
        }

        $updated = $this->tags->update($tag, $data);

        Log::info('Tag updated', [
            'tag_id' => $updated->id,
            'tenant_id' => $updated->tenant_id,
        ]);

        return $updated;
    }

    public function delete(Tag $tag): void
    {
        $this->tags->delete($tag);

        Log::info('Tag deleted', [
            'tag_id' => $tag->id,
            'tenant_id' => $tag->tenant_id,
        ]);
    }

    private function resolveUniqueSlug(string $requestedSlug, string $name, ?int $excludeId = null): string
    {
        $base = $requestedSlug !== '' ? $requestedSlug : Str::slug($name);

        if (! $this->tags->slugExists($base, $excludeId)) {
            return $base;
        }

        for ($i = 2; $i <= 100; $i++) {
            $candidate = "{$base}-{$i}";

            if (! $this->tags->slugExists($candidate, $excludeId)) {
                return $candidate;
            }
        }

        throw new DomainException(
            "Could not generate a unique slug for tag \"{$name}\" after 100 attempts."
        );
    }
}
