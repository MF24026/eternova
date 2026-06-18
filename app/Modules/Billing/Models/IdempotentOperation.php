<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per logical money operation. The (key, operation, tenant_id) UNIQUE enforces
 * exactly-once semantics at the DB level; this model is just the accessor IdempotencyService
 * uses to read/advance that row's status.
 *
 * @property string $key
 * @property string $operation
 * @property string $tenant_id
 * @property string $status
 * @property string|null $result
 */
final class IdempotentOperation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'key',
        'operation',
        'tenant_id',
        'status',
        'result',
        'error_message',
        'expires_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
