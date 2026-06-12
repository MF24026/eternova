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
 * Orchestrates expense creation from a receipt upload.
 *
 * The upload flow is intentionally fire-and-forget for OCR:
 *   1. File stored on disk (within transaction scope for atomicity).
 *   2. Expense row created as a DRAFT (ocr_status=pending, is_verified=false).
 *   3. ProcessReceiptOcrJob dispatched — the job fills ocr_data and pre-fills
 *      draft fields from OCR suggestions so the user has less to type.
 *   4. Response returned immediately; OCR happens asynchronously.
 *
 * Pre-filling draft fields from OCR suggestions:
 *   The job pre-fills amount_cents, vendor, and expense_date from the OCR
 *   result onto the draft expense. is_verified stays false so the record is
 *   always visually marked "unconfirmed" until the user reviews it in E7.
 *   Rationale: saves the user from retyping what Tesseract already extracted
 *   correctly; the "unverified" badge makes it clear the data needs review.
 *
 * Tenant resolution:
 *   Resolves from the container-bound 'currentTenant' (HTTP context, set by
 *   EnsureTenant middleware). Falls back to null — the caller must always be
 *   running in a tenant-scoped HTTP request, so null here is a programmer error
 *   and we throw rather than silently create a tenant-less expense.
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
            'tenant_id'    => $tenant->id,
            'path'         => $storagePath,
            'disk'         => $disk,
            'size_bytes'   => $file->getSize(),
            'actor_id'     => $actor?->id,
        ]);

        try {
            /** @var Expense $expense */
            $expense = DB::transaction(static function () use (
                $tenant, $branch, $actor, $storagePath,
            ): Expense {
                return Expense::create([
                    'tenant_id'        => $tenant->id,
                    'branch_id'        => $branch?->id,
                    'created_by'       => $actor?->id,
                    'receipt_path'     => $storagePath,
                    'ocr_status'       => 'pending',
                    'is_verified'      => false,
                    // Placeholder values — overwritten by the user in E7 after OCR
                    // pre-fills the draft from suggestions (see ProcessReceiptOcrJob).
                    'expense_date'     => now()->toDateString(),
                    'description'      => 'Factura sin verificar',
                    'amount_cents'     => 0,
                ]);
            });
        } catch (\Throwable $e) {
            // DB insert failed — clean up the orphaned file so storage does not
            // accumulate unlinked receipts.
            Storage::disk($disk)->delete($storagePath);

            Log::warning('Receipt expense creation failed; orphaned file deleted', [
                'tenant_id' => $tenant->id,
                'path'      => $storagePath,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }

        ProcessReceiptOcrJob::dispatch($expense);

        Log::info('Receipt upload complete; OCR job dispatched', [
            'expense_id' => $expense->id,
            'tenant_id'  => $tenant->id,
        ]);

        return $expense;
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
                'ExpenseService::createFromReceiptUpload() requires an active tenant context. '
                . 'Ensure the route runs behind the EnsureTenant middleware.'
            );
        }

        return $tenant;
    }
}
