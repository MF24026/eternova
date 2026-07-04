<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a receipt upload for the expense OCR pipeline.
 *
 * Authorization is double-layered:
 *   1. FormRequest::authorize() — fast-path check before validation runs.
 *   2. Controller calls $this->authorize('create', Expense::class) — explicit gate.
 *
 * The `branch_id` field is optional. When omitted, the expense is not linked
 * to a specific branch (useful for tenants that operate a single branch without
 * needing to select it explicitly on every upload).
 */
final class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Expense::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) (config('expenses.receipt_max_bytes', 10 * 1024 * 1024) / 1024);

        return [
            'receipt' => [
                'required',
                'file',
                "max:{$maxKilobytes}",
                'mimes:jpg,jpeg,png,pdf',
            ],
            'branch_id' => [
                'nullable',
                'string',
                tenant_exists('branches'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = round(config('expenses.receipt_max_bytes', 10 * 1024 * 1024) / 1024 / 1024, 0);

        return [
            'receipt.required' => 'A receipt file is required.',
            'receipt.file' => 'The upload must be a file.',
            'receipt.max' => "The receipt may not be larger than {$maxMb} MB.",
            'receipt.mimes' => 'The receipt must be a JPEG, PNG, or PDF file.',
            'branch_id.exists' => 'The selected branch does not exist.',
        ];
    }
}
