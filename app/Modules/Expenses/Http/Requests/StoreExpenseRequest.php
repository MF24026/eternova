<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a manual expense creation request.
 *
 * This request is for direct staff entry without a receipt. Receipts go through
 * StoreReceiptRequest → ReceiptUploadController.
 *
 * amount_cents must be a non-negative integer (zero is valid for a $0 expense row,
 * though unusual). The frontend must convert from the display amount to centavos
 * before submitting.
 */
final class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorizes via $this->authorize('create', Expense::class)
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:500'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['nullable', 'integer', tenant_exists('expense_categories')],
            'branch_id' => ['nullable', 'string', tenant_exists('branches')],
            'vendor' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,transfer,other'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
