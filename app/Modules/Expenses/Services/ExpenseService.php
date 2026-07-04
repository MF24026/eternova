<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Services;

use App\Models\User;
use App\Modules\Expenses\Jobs\ProcessReceiptOcrJob;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates expense creation, updates, and deletion.
 *
 * Two creation paths:
 *   - createFromReceiptUpload(): file stored → DRAFT (ocr_status=pending, is_verified=false)
 *     → OCR job dispatched. Async; caller returns immediately.
 *   - createManual(): direct staff entry with no receipt. is_verified=true, ocr_status=none.
 *
 * The verify path goes through update(): when the body includes is_verified=true alongside
 * corrected fields, this single method handles both "edit" and "confirm draft" in one call.
 *
 * Tenant resolution:
 *   Resolves from the container-bound 'currentTenant' (HTTP context, set by EnsureTenant
 *   middleware). A null result indicates a misconfigured route — throws immediately.
 */
final readonly class ExpenseService
{
    /**
     * Store a receipt file, create a DRAFT expense, and dispatch the OCR job.
     *
     * The file store + row insert happen inside a transaction so that a failed
     * DB insert does not leave an orphaned file on disk (we delete the file in
     * the catch branch). A failed file.put() prevents the DB insert from
     * running at all because it throws before the transaction is entered.
     *
     * @throws RuntimeException When no active tenant context is found.
     */
    public function createFromReceiptUpload(
        UploadedFile $file,
        ?Branch $branch,
        ?User $actor,
    ): Expense {
        $tenant = $this->resolveTenant();

        $disk = config('expenses.receipt_disk');
        $extension = $file->extension() ?: 'jpg';
        $ulid = Str::ulid()->toString();
        $storagePath = "tenants/{$tenant->id}/receipts/{$ulid}.{$extension}";

        // Store the file before opening the transaction so the DB insert is not
        // held open while waiting on slow storage I/O.
        Storage::disk($disk)->put($storagePath, $file->get());

        Log::info('Receipt file stored', [
            'tenant_id' => $tenant->id,
            'path' => $storagePath,
            'disk' => $disk,
            'size_bytes' => $file->getSize(),
            'actor_id' => $actor?->id,
        ]);

        try {
            /** @var Expense $expense */
            $expense = DB::transaction(static function () use (
                $tenant, $branch, $actor, $storagePath,
            ): Expense {
                return Expense::create([
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branch?->id,
                    'created_by' => $actor?->id,
                    'receipt_path' => $storagePath,
                    'ocr_status' => 'pending',
                    'is_verified' => false,
                    // Placeholder values — overwritten by the user in E7 after OCR
                    // pre-fills the draft from suggestions (see ProcessReceiptOcrJob).
                    'expense_date' => now()->toDateString(),
                    'description' => 'Factura sin verificar',
                    'amount_cents' => 0,
                ]);
            });
        } catch (\Throwable $e) {
            // DB insert failed — clean up the orphaned file so storage does not
            // accumulate unlinked receipts.
            Storage::disk($disk)->delete($storagePath);

            Log::warning('Receipt expense creation failed; orphaned file deleted', [
                'tenant_id' => $tenant->id,
                'path' => $storagePath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        ProcessReceiptOcrJob::dispatch($expense);

        Log::info('Receipt upload complete; OCR job dispatched', [
            'expense_id' => $expense->id,
            'tenant_id' => $tenant->id,
        ]);

        return $expense;
    }

    /**
     * Create a manually entered expense — no receipt, immediately verified.
     *
     * Staff manually enter the expense details so the record does not go through
     * the OCR draft pipeline. It is created as is_verified=true, ocr_status=none.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException When no active tenant context is found.
     */
    public function createManual(array $data, ?User $actor): Expense
    {
        $tenant = $this->resolveTenant();

        $expense = Expense::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $data['branch_id'] ?? null,
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'description' => (string) $data['description'],
            'amount_cents' => (int) $data['amount_cents'],
            'expense_date' => (string) $data['expense_date'],
            'vendor' => $data['vendor'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
            'ocr_status' => 'none',
            'is_verified' => true,
            'created_by' => $actor?->id,
        ]);

        Log::info('Manual expense created', [
            'expense_id' => $expense->id,
            'tenant_id' => $tenant->id,
            'amount_cents' => $expense->amount_cents,
            'actor_id' => $actor?->id,
        ]);

        return $expense;
    }

    /**
     * Update an existing expense's fields.
     *
     * This is also the verification path: when the body includes is_verified=true
     * alongside corrected fields, the single call both applies corrections and
     * confirms the draft in one atomic write.
     *
     * Only the keys present in $data are updated — absent keys are left unchanged.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data): Expense
    {
        $updatable = array_intersect_key($data, array_flip([
            'expense_category_id',
            'branch_id',
            'description',
            'amount_cents',
            'expense_date',
            'vendor',
            'payment_method',
            'notes',
            'is_verified',
        ]));

        $expense->update($updatable);

        Log::info('Expense updated', [
            'expense_id' => $expense->id,
            'tenant_id' => $expense->tenant_id,
            'is_verified' => $expense->is_verified,
            'fields' => array_keys($updatable),
        ]);

        return $expense;
    }

    /**
     * Soft-delete an expense and remove its receipt file from storage (best-effort).
     *
     * The receipt file delete is best-effort: if storage fails we log the error but
     * do not prevent the soft-delete from completing. A background cleanup job can
     * sweep orphaned files later if needed.
     */
    public function delete(Expense $expense): void
    {
        $receiptPath = $expense->receipt_path;
        $disk = config('expenses.receipt_disk');

        $expense->delete();

        Log::info('Expense soft-deleted', [
            'expense_id' => $expense->id,
            'tenant_id' => $expense->tenant_id,
            'receipt_path' => $receiptPath,
        ]);

        if ($receiptPath !== null) {
            try {
                Storage::disk($disk)->delete($receiptPath);
            } catch (\Throwable $e) {
                // Best-effort: the soft-delete already completed. Log and move on.
                Log::warning('Failed to delete receipt file after expense deletion', [
                    'expense_id' => $expense->id,
                    'receipt_path' => $receiptPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Resolve the current tenant from the service container.
     *
     * This service is only called from authenticated, tenant-scoped HTTP routes.
     * A null result indicates a misconfigured route that lacks the 'tenant'
     * middleware — throw so the bug is immediately visible.
     *
     * @throws RuntimeException When no active tenant context is found.
     */
    private function resolveTenant(): Tenant
    {
        $tenant = current_tenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException(
                'ExpenseService requires an active tenant context. '
                .'Ensure the route runs behind the EnsureTenant middleware.'
            );
        }

        return $tenant;
    }
}
