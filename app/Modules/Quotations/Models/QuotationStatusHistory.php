<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Quotations\QuotationStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every status transition on a Quotation.
 *
 * Rows are immutable once written: no updated_at, UPDATED_AT = null
 * with created_at stamped automatically by Eloquent on insert.
 *
 * from_status is null for the initial creation entry — the quotation was
 * born directly into to_status (new quotations start as 'draft').
 *
 * user_id is null when the transition was made by the system (e.g.
 * ExpireQuotationsJob running nightly — no identified staff member).
 */
final class QuotationStatusHistory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<QuotationStatusHistoryFactory> */
    use HasFactory;

    // Eloquent pluralises class names: "quotation_status_history" →
    // "quotation_status_histories". Explicitly name the table to match the migration.
    protected $table = 'quotation_status_history';

    // Eloquent will stamp created_at on insert; updated_at is not tracked.
    public const UPDATED_AT = null;

    protected static function newFactory(): QuotationStatusHistoryFactory
    {
        return QuotationStatusHistoryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'quotation_id',
        'from_status',
        'to_status',
        'user_id',
        'note',
    ];

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
