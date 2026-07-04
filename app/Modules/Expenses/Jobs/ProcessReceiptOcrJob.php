<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Jobs;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Ocr\OcrDriverInterface;
use App\Modules\Expenses\Ocr\OcrException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Processes a receipt image or PDF through the configured OCR driver.
 *
 * The job operates by expense ID rather than serializing the full Eloquent model.
 * This avoids stale-model issues where the model state has changed between
 * dispatch and handle (e.g. the expense was deleted during queue backlog).
 *
 * Tenant scope in the job:
 *   Queued jobs run outside a normal HTTP request — there is no EnsureTenant
 *   middleware and no 'currentTenant' container binding. We fetch the expense
 *   with withoutGlobalScopes() to bypass the BelongsToTenant filter, then
 *   operate exclusively on that single row. No cross-tenant data is ever read:
 *   we hold a single expense ID and write back to that same row only.
 *
 * Pre-fill from OCR suggestions:
 *   On success, the job pre-fills amount_cents, vendor, and expense_date from
 *   the OCR result onto the draft expense. is_verified remains false so the
 *   record is always visually marked as "unconfirmed" until the staff member
 *   reviews and approves it in the E7 verification UI.
 *   Rationale: pre-filling saves the user retyping data Tesseract already
 *   extracted; the unverified badge makes it clear the values need review.
 *
 * Retry strategy:
 *   3 tries with an exponential backoff (60 s, 120 s). Most transient OCR
 *   failures (Tesseract process crash, tmp-filesystem hiccup) resolve within
 *   the first retry. After all retries are exhausted, failed() sets
 *   ocr_status=failed so the staff member can see the receipt and fill in
 *   the fields manually.
 */
final class ProcessReceiptOcrJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Backoff in seconds between retries: 60 s, 120 s, (exhausted on 3rd try).
     *
     * @var list<int>
     */
    public array $backoff = [60, 120];

    public function __construct(
        private readonly int $expenseId,
    ) {}

    /**
     * Named constructor so callers use a typed Expense, not a raw string.
     *
     * ExpenseService dispatches via this factory method; the job internally
     * stores only the ID to avoid stale-model serialisation.
     */
    public static function dispatch(Expense $expense): void
    {
        self::dispatchJob((int) $expense->id);
    }

    /**
     * Run OCR on the receipt and persist suggestions onto the draft expense.
     *
     * On OcrException the status is set to 'failed' and the job does NOT retry
     * (the exception is swallowed after logging). This is intentional: a file
     * that fails OCR structurally (corrupt, unsupported format, illegible) will
     * never succeed on retry — let the staff member fill in the fields manually.
     *
     * On any other Throwable the exception bubbles up so the queue retries it
     * (transient infrastructure errors: tmp-disk full, process crash, etc.).
     */
    public function handle(OcrDriverInterface $driver): void
    {
        $expense = Expense::withoutGlobalScopes()->find($this->expenseId);

        if ($expense === null) {
            Log::warning('ProcessReceiptOcrJob: expense not found; skipping', [
                'expense_id' => $this->expenseId,
            ]);

            return;
        }

        // Guard against double-processing: if OCR already completed (possibly
        // by a duplicate dispatch or a failed retry that somehow committed),
        // bail out without touching the row.
        if (in_array($expense->ocr_status, ['done', 'failed'], strict: true)) {
            Log::info('ProcessReceiptOcrJob: already processed; skipping', [
                'expense_id' => $expense->id,
                'ocr_status' => $expense->ocr_status,
            ]);

            return;
        }

        $expense->update(['ocr_status' => 'processing']);

        $disk = config('expenses.receipt_disk');
        $absolutePath = Storage::disk($disk)->path($expense->receipt_path);

        try {
            $result = $driver->extract($absolutePath);

            // Pre-fill draft fields from OCR suggestions.
            // is_verified stays false — the staff member must confirm in E7.
            $expense->update([
                'ocr_status' => 'done',
                'ocr_data' => $result->toArray(),
                'amount_cents' => $result->amountCents ?? 0,
                'vendor' => $result->vendor,
                'expense_date' => $result->date ?? now()->toDateString(),
            ]);

            Log::info('ProcessReceiptOcrJob: OCR complete', [
                'expense_id' => $expense->id,
                'tenant_id' => $expense->tenant_id,
                'vendor' => $result->vendor,
                'amount_cents' => $result->amountCents,
                'confidence' => $result->confidence,
            ]);
        } catch (OcrException $e) {
            // Structural failure — do not retry. Mark as failed so the UI
            // can prompt the staff member to fill in the fields manually.
            $expense->update(['ocr_status' => 'failed']);

            Log::warning('ProcessReceiptOcrJob: OCR extraction failed', [
                'expense_id' => $expense->id,
                'tenant_id' => $expense->tenant_id,
                'error' => $e->getMessage(),
            ]);

            // Swallow: do not let the job fail and trigger queue retries for
            // structural OCR failures that will never recover.
        }
        // Any other Throwable bubbles up → queue retries per $tries / $backoff.
    }

    /**
     * Called by the queue after all retry attempts are exhausted.
     *
     * Sets ocr_status=failed so the UI shows the receipt as needing manual
     * entry. The expense is still a valid draft; the staff member can fill
     * in fields and verify it manually.
     */
    public function failed(\Throwable $e): void
    {
        $expense = Expense::withoutGlobalScopes()->find($this->expenseId);

        if ($expense !== null) {
            $expense->update(['ocr_status' => 'failed']);
        }

        Log::error('ProcessReceiptOcrJob: all retries exhausted', [
            'expense_id' => $this->expenseId,
            'error' => $e->getMessage(),
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Internal dispatch adapter — holds the raw ID, not the model.
     *
     * This is a private static relay so the public `dispatch(Expense)` API
     * remains typed while the job's own constructor stores the raw ID.
     */
    private static function dispatchJob(int $id): void
    {
        dispatch(new self($id));
    }
}
