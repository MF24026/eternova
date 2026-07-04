<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new expense category creation request.
 *
 * Category names must be unique per tenant so that the expense form's category
 * dropdown does not present ambiguous entries. The uniqueness rule is enforced at
 * the application layer here (and backed by the DB UNIQUE index on tenant_id+name).
 */
final class StoreExpenseCategoryRequest extends FormRequest
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

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                // Unique within this tenant — two different tenants can have the same name.
                Rule::unique('expense_categories')->where('tenant_id', $tenantId),
            ],
            'type' => ['required', 'string', 'in:operating,products,payroll,rent,other'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
