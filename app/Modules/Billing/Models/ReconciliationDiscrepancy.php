<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A flagged drift between our subscription state and what the gateway reports, written by
 * the reconcile cron for manual operator review. Not auto-resolved — money discrepancies
 * always get a human.
 */
final class ReconciliationDiscrepancy extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'our_state',
        'gateway_state',
        'diff_summary',
        'resolved',
        'detected_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'our_state' => 'array',
        'gateway_state' => 'array',
        'resolved' => 'boolean',
        'detected_at' => 'datetime',
    ];
}
