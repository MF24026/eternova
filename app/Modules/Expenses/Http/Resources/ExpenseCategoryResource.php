<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Http\Request;

/**
 * Transforms a single ExpenseCategory for API output.
 *
 * expenses_count is included when the model was loaded with withCount('expenses')
 * so the category list can show usage without a separate query.
 *
 * @property ExpenseCategory $resource
 */
final class ExpenseCategoryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var ExpenseCategory $category */
        $category = $this->resource;

        return [
            'id'             => $category->id,
            'name'           => $category->name,
            'type'           => $category->type,
            'is_active'      => $category->is_active,
            'expenses_count' => $this->whenCounted('expenses'),
            'created_at'     => $category->created_at?->toIso8601String(),
        ];
    }
}
