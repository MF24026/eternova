<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Support\SlugValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Category|null $category */
        $category = $this->route('category');

        return $category !== null
            && ($this->user()?->can('update', $category) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');

        $tenantId = current_tenant()?->id;

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! SlugValidator::isValid((string) $value)) {
                        $fail('The slug must be a lowercase alphanumeric slug (RFC 1035).');
                    }
                },
                Rule::unique('categories', 'slug')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($category?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                tenant_exists('categories'),
                static function (string $attribute, mixed $value, \Closure $fail) use ($category): void {
                    if ($value === null) {
                        return;
                    }

                    $parentId = (int) $value;

                    // Prevent self-reference
                    if ($parentId === $category->id) {
                        $fail('A category cannot be its own parent.');

                        return;
                    }

                    // The BelongsToTenant global scope on Category ensures this
                    // lookup is already scoped to the current tenant.
                    $parent = Category::find($parentId);

                    if ($parent === null) {
                        $fail('The selected parent category does not exist in your tenant.');
                    }
                },
            ],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
