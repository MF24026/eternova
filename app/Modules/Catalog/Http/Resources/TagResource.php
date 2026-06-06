<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\Tag;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @property Tag $resource
 */
final class TagResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Tag $tag */
        $tag = $this->resource;

        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'created_at' => $tag->created_at?->toIso8601String(),
            'updated_at' => $tag->updated_at?->toIso8601String(),
        ];
    }
}
