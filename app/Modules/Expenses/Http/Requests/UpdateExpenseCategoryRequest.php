<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an expense category update request.
 *
 * The unique-name rule ignores the current category row so that a rename that
 * keeps the same name (touching only is_active or type) does not fail validation.
 */
final class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorizes via $this->authorize('manageCategories', Expense::class)
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenantId = current_tenant()?->id;

        /** @var ExpenseCategory $category */
        $category = $this->route('category');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                // Ignore the current row so a no-op rename does not fail.
                Rule::unique('expense_categories')
                    ->where('tenant_id', $tenantId)
                    ->ignore($category->id),
            ],
            'type'      => ['sometimes', 'required', 'string', 'in:operating,products,payroll,rent,other'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
