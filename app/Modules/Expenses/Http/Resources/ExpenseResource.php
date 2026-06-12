<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Resources;

use App\Modules\Expenses\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Transforms an Expense model to a JSON response.
 *
 * E3 minimal shape: fields needed by the upload response and the OCR status
 * polling endpoint. E4 will extend this with the full expense detail shape
 * (category, notes, payment_method, creator, etc.) when the CRUD module ships.
 *
 * receipt_url:
 *   A public-access URL resolved via Storage::url(). null when no receipt has
 *   been attached (manual entry). Consumers should not store this URL — it can
 *   change if the storage disk or path strategy changes. Always re-fetch.
 *
 * amount_cents:
 *   Integer centavos. Clients must format for display (divide by 100, apply
 *   locale-specific currency formatting). Never divide server-side.
 *
 * @mixin Expense
 */
final class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Expense $expense */
        $expense = $this->resource;

        return [
            'id'           => $expense->id,
            'ocr_status'   => $expense->ocr_status,
            'is_verified'  => $expense->is_verified,
            'amount_cents' => $expense->amount_cents,
            'expense_date' => $expense->expense_date?->toDateString(),
            'description'  => $expense->description,
            'vendor'       => $expense->vendor,
            'receipt_url'  => $expense->receipt_path
                ? Storage::disk(config('expenses.receipt_disk'))->url($expense->receipt_path)
                : null,
            'ocr_data'     => $expense->ocr_data,
            'created_at'   => $expense->created_at?->toIso8601String(),
        ];
    }
}
