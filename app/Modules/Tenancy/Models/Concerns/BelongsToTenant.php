<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    /**
     * Boot the trait: apply global scope and auto-fill tenant_id on create.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $model): void {
            if (empty($model->tenant_id)) {
                /** @var Tenant|null $currentTenant */
                $currentTenant = app()->bound('currentTenant') ? app('currentTenant') : null;

                if ($currentTenant !== null) {
                    $model->tenant_id = $currentTenant->id;
                }
            }
        });
    }

    /**
     * Relationship back to the owning tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
