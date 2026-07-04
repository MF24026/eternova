<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only billing audit trail. Once written, an entry is immutable: the only way to
 * "correct" the record is to append a compensating entry. This is enforced here (not just
 * by convention) so a stray update/delete in a future refactor fails loudly.
 *
 * Not tenant-scoped (no BelongsToTenant): the SaaS operator reads across all tenants, and
 * the trail must survive a tenant teardown (FK is nullOnDelete).
 */
final class BillingAuditLog extends Model
{
    protected $table = 'billing_audit_log';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'event_type',
        'payload',
        'correlation_id',
        'occurred_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'billing_audit_log is append-only; append a compensating entry instead of updating.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException('billing_audit_log is append-only; entries cannot be deleted.');
        });
    }
}
