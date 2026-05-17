<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * When no currentTenant is bound (e.g. super-admin context or CLI),
     * the scope is intentionally skipped to allow full-table access.
     */
    public function apply(Builder $builder, Model $model): void
    {
        /** @var Tenant|null $currentTenant */
        $currentTenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($currentTenant !== null) {
            $builder->where($model->getTable() . '.tenant_id', $currentTenant->id);
        }
    }
}
