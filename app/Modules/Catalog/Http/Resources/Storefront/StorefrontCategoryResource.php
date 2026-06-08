<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\Storefront;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Request;

/**
 * Public category representation for the storefront category tree.
 *
 * Exposes only: id, name, slug, image_url, children (nested), products_count.
 *
 * NEVER exposed: tenant_id, parent_id (internal FK), deleted_at, admin timestamps,
 * description (not needed for tree navigation; may contain internal notes).
 *
 * @property Category $resource
 */
final class StorefrontCategoryResource extends BaseResource
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
            'image_url' => $category->image_url,
            'sort_order' => $category->sort_order,
            // Nested children loaded by the controller; empty array when leaf
            'children' => $this->when(
                $category->relationLoaded('children'),
                static fn () => self::collection($category->children),
            ),
            // Count of active products in this category (appended by query)
            'products_count' => $category->products_count ?? 0,
        ];
    }
}
