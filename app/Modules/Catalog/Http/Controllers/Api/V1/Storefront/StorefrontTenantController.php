<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Resources\Storefront\StorefrontTenantResource;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Returns the public branding snapshot for the current tenant's storefront.
 *
 * No auth. No policy. Tenant is resolved by EnsureTenant middleware.
 */
final class StorefrontTenantController extends Controller
{
    public function show(): StorefrontTenantResource
    {
        /** @var Tenant $tenant */
        $tenant = app('currentTenant');

        return new StorefrontTenantResource($tenant);
    }
}
