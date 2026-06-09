<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant, per-year sequence counter for order numbers.
 *
 * This model is an implementation detail of OrderService::nextOrderNumber() and
 * should not be used outside that method. Direct access outside the service is
 * intentionally unsupported — the sequence must always be incremented inside
 * a DB::transaction() with a FOR UPDATE lock.
 */
final class OrderSequence extends Model
{
    use BelongsToTenant;

    protected $table = 'order_sequences';

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
