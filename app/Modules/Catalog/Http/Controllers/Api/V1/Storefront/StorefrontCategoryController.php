<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\Storefront\StorefrontCategoryResource;
use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Returns the public active category tree for the current tenant's storefront.
 *
 * No auth. No policy. Tenant scope applied automatically via BelongsToTenant.
 */
final class StorefrontCategoryController extends Controller
{
    /**
     * Active root categories with their first-level children, each annotated with
     * a count of currently active products.
     *
     * Products count is loaded via a constrained relationship count so only
     * active products are counted (inactive products are invisible in the storefront).
     */
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->withCount([
                'products' => static fn ($q) => $q->where('is_active', true),
            ])
            ->with([
                'children' => static function ($q): void {
                    $q->where('is_active', true)
                        ->orderBy('sort_order')
                        ->withCount([
                            'products' => static fn ($sub) => $sub->where('is_active', true),
                        ]);
                },
            ])
            ->orderBy('sort_order')
            ->get();

        return StorefrontCategoryResource::collection($categories);
    }
}
