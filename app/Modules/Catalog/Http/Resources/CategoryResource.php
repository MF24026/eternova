<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @property Category $resource
 */
final class CategoryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'image_url' => $category->image_url,
            'parent_id' => $category->parent_id,
            'parent' => $this->when(
                $category->relationLoaded('parent'),
                static fn () => $category->parent !== null
                    ? new self($category->parent)
                    : null,
            ),
            'children' => $this->when(
                $category->relationLoaded('children'),
                static fn () => self::collection($category->children),
            ),
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
            'products_count' => $category->products_count ?? 0,
            'depth' => $category->depth(),
            'deleted_at' => $category->deleted_at?->toIso8601String(),
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }
}
