<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Expenses\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Transforms a single Expense for API output.
 *
 * amount_cents is exposed as a raw centavo integer — formatting for display
 * (currency symbol, decimal places, locale) is the frontend's responsibility.
 *
 * receipt_url is resolved at serialisation time via Storage::url(). Consumers
 * must not cache this URL — always re-fetch from the resource.
 *
 * Nested relations (category, branch, creator) are conditionally included only
 * when explicitly loaded to prevent accidental N+1.
 *
 * @property Expense $resource
 */
final class ExpenseResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Expense $expense */
        $expense = $this->resource;

        return [
            'id'            => $expense->id,
            'description'   => $expense->description,
            'amount_cents'  => $expense->amount_cents,
            'expense_date'  => $expense->expense_date?->toDateString(),
            'vendor'        => $expense->vendor,
            'payment_method' => $expense->payment_method,
            'ocr_status'    => $expense->ocr_status,
            'is_verified'   => $expense->is_verified,
            'ocr_data'      => $expense->ocr_data,
            'receipt_url'   => $expense->receipt_path
                ? $this->resolveReceiptUrl($expense->receipt_path)
                : null,
            'notes'         => $expense->notes,
            'created_at'    => $expense->created_at?->toIso8601String(),
            'updated_at'    => $expense->updated_at?->toIso8601String(),

            'category' => $this->when(
                $expense->relationLoaded('category') && $expense->category !== null,
                static fn () => [
                    'id'   => $expense->category?->id,
                    'name' => $expense->category?->name,
                    'type' => $expense->category?->type,
                ],
            ),

            'branch' => $this->when(
                $expense->relationLoaded('branch') && $expense->branch !== null,
                static fn () => [
                    'id'   => $expense->branch?->id,
                    'name' => $expense->branch?->name,
                ],
            ),

            'creator' => $this->when(
                $expense->relationLoaded('creator') && $expense->creator !== null,
                static fn () => [
                    'id'   => $expense->creator?->id,
                    'name' => $expense->creator?->name,
                ],
            ),
        ];
    }

    /**
     * Resolve a browser-usable URL for the receipt.
     *
     * A private object-storage disk (R2/S3) has no public path, so we mint a
     * short-lived signed URL. The local "public" disk does not support signed
     * URLs and serves a permanent /storage path instead — fall back to url().
     */
    private function resolveReceiptUrl(string $path): string
    {
        $disk = Storage::disk(config('expenses.receipt_disk'));

        try {
            return $disk->temporaryUrl(
                $path,
                now()->addMinutes((int) config('expenses.receipt_url_ttl', 30)),
            );
        } catch (\RuntimeException) {
            // Driver does not support temporary URLs (e.g. local) — use url().
            return $disk->url($path);
        }
    }
}
