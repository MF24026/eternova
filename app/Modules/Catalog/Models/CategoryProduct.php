<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * Pivot model for the category_product M2M table.
 *
 * Why a dedicated Pivot model: the pivot carries tenant_id and sort_order, and
 * tenant_id must be auto-set from the current tenant context on every attach()
 * call — same guarantee as BelongsToTenant but adapted for Pivot (which cannot
 * use the trait directly because Pivot boots differently from Model).
 */
final class CategoryProduct extends Pivot
{
    /**
     * The pivot table has its own auto-increment id column.
     */
    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'category_id',
        'product_id',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        self::creating(static function (self $pivot): void {
            if (! empty($pivot->tenant_id)) {
                return;
            }

            /** @var Tenant|null $currentTenant */
            $currentTenant = app()->bound('currentTenant') ? app('currentTenant') : null;

            if ($currentTenant instanceof Tenant) {
                $pivot->tenant_id = $currentTenant->id;

                return;
            }

            // No tenant in the container (e.g. an attach() called from test setup or a
            // queued job). Derive tenant_id from the related product or category, both of
            // which always carry it. This keeps the pivot self-sufficient without forcing
            // every caller to resolve a tenant first, while still guaranteeing the column
            // is never left null.
            $derivedTenantId = Product::withoutGlobalScopes()
                ->whereKey($pivot->product_id)
                ->value('tenant_id')
                ?? Category::withoutGlobalScopes()
                    ->whereKey($pivot->category_id)
                    ->value('tenant_id');

            if ($derivedTenantId !== null) {
                $pivot->tenant_id = $derivedTenantId;

                return;
            }

            throw new LogicException(
                CategoryProduct::class.'::creating() requires a resolved tenant in the container. '
                .'Either resolve a tenant via EnsureTenant middleware, call '
                ."app()->instance('currentTenant', \$tenant) in tests, "
                .'or pass tenant_id explicitly.'
            );
        });
    }
}
