<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

final class ReorderCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reorder', Category::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', tenant_exists('categories')],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
            'items.*.parent_id' => [
                'nullable',
                'integer',
                tenant_exists('categories'),
                // Prevent an item from being its own parent
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    // Extract index from attribute path like "items.2.parent_id"
                    $parts = explode('.', $attribute);
                    // $parts[1] is the numeric index
                    $index = isset($parts[1]) ? (int) $parts[1] : -1;

                    $items = request()->input('items', []);
                    $itemId = isset($items[$index]['id']) ? (int) $items[$index]['id'] : null;

                    if ($itemId !== null && (int) $value === $itemId) {
                        $fail("An item cannot be its own parent (items.{$index}.parent_id).");
                    }
                },
            ],
        ];
    }
}
