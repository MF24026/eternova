<?php

declare(strict_types=1);

namespace App\Modules\POS\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\POS\CashMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual cash in/out during a register session (ingreso / retiro): supplier payment,
 * float top-up, withdrawal to vault, etc. Feeds the arqueo ladder. Amount always
 * positive; `type` carries the sign.
 */
final class CashMovement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    protected static function newFactory(): CashMovementFactory
    {
        return CashMovementFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cash_register_session_id',
        'user_id',
        'type',
        'amount_cents',
        'reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<CashRegisterSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
