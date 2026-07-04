<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant, per-year sequence counter for quotation numbers.
 *
 * This model is an implementation detail of QuotationService::nextQuotationNumber()
 * and should not be used outside that method. Direct access outside the service is
 * intentionally unsupported — the sequence must always be incremented inside a
 * DB::transaction() with a FOR UPDATE lock to prevent collisions under concurrent
 * requests.
 *
 * Mirrors ReservationSequence and OrderSequence exactly. See quotation_sequences
 * migration for the concurrency rationale.
 */
final class QuotationSequence extends Model
{
    use BelongsToTenant;

    protected $table = 'quotation_sequences';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'year',
        'last_sequence',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'year' => 'integer',
        'last_sequence' => 'integer',
    ];
}
