<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Expenses\ExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A business expense record for the tenant.
 *
 * Monetary values are always stored in centavos (integer). Never expose raw
 * amount_cents in API responses — format in API Resources (S6-E4).
 *
 * OCR pipeline lifecycle:
 *   1. Staff uploads a receipt → Expense created with ocr_status=pending, is_verified=false.
 *   2. ProcessReceiptOcrJob runs → sets ocr_status=processing, then done|failed + ocr_data.
 *   3. Staff reviews OCR suggestions in the verification UI → confirms → is_verified=true.
 *   Manual entry (no receipt) → created directly with is_verified=true, ocr_status=none.
 *
 * is_verified is the human gate. The service layer enforces it; the DB column is
 * the authoritative source of truth (never trust a cached in-memory value).
 *
 * receipt_path is a storage-disk key, never an absolute filesystem path. Use
 * Storage::url($expense->receipt_path) to resolve to a public URL.
 */
final class Expense extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): ExpenseFactory
    {
        return ExpenseFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'expense_category_id',
        'description',
        'amount_cents',
        'expense_date',
        'vendor',
        'payment_method',
        'receipt_path',
        'ocr_status',
        'ocr_data',
        'is_verified',
        'notes',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_cents'  => 'integer',
        'expense_date'  => 'date',
        'ocr_data'      => 'array',
        'is_verified'   => 'boolean',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * The staff member who entered or uploaded this expense.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether this expense is a draft awaiting staff verification.
     *
     * Returns true for both OCR-prefilled drafts (is_verified=false, ocr_status=done)
     * and failed OCR records (is_verified=false, ocr_status=failed). The caller
     * must call ->fresh() after factory create() to hydrate DB-applied defaults
     * before relying on this helper in tests.
     */
    public function isDraft(): bool
    {
        return ! $this->is_verified;
    }
}
