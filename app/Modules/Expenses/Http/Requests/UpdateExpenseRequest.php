<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an expense update request.
 *
 * All fields are optional (sometimes) — callers may send only the fields they
 * wish to change. This request also covers the verification path: including
 * is_verified=true with corrected fields confirms a draft in one call.
 */
final class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorizes via $this->authorize('update', $expense)
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'required', 'string', 'max:500'],
            'amount_cents' => ['sometimes', 'required', 'integer', 'min:0'],
            'expense_date' => ['sometimes', 'required', 'date'],
            'expense_category_id' => ['sometimes', 'nullable', 'integer', tenant_exists('expense_categories')],
            'branch_id' => ['sometimes', 'nullable', 'string', tenant_exists('branches')],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'in:cash,card,transfer,other'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_verified' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
