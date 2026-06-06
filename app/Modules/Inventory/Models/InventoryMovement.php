<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Inventory\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable ledger row recording a single stock change at a Branch for a ProductVariant.
 *
 * Append-only invariants enforced here:
 *  1. No `updated_at` column — the migration creates only `created_at`.
 *  2. The `updating` event throws LogicException, preventing accidental writes through
 *     $movement->update([...]) or $movement->save() after hydration.
 *
 * Compensating entries (corrections) are expressed as new rows with type=adjustment,
 * never by mutating an existing row.
 */
final class InventoryMovement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    // Append-only: no `updated_at` in DB schema.
    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    // Movement type constants — use these at call sites to avoid magic strings.
    public const TYPE_ENTRY = 'entry';

    public const TYPE_EXIT = 'exit';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_TRANSFER = 'transfer';

    protected static function newFactory(): InventoryMovementFactory
    {
        return InventoryMovementFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'product_variant_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'notes',
        'user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        // Defense-in-depth: throw immediately if any code path tries to update a movement.
        // The correct pattern for corrections is a new row with type=adjustment.
        self::updating(static function (): never {
            throw new \LogicException(
                'inventory_movements is an append-only ledger. '
                .'To correct a mistake, record a new row with type=adjustment. '
                .'Never mutate an existing movement.'
            );
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
