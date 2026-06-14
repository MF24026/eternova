<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    /**
     * Return the currently authenticated user with their tenant memberships.
     *
     * The /me route is intentionally NOT behind the 'tenant' middleware: it is
     * also called from non-tenant origins (super-admin panel, public storefront
     * boot), where EnsureTenant would abort(404). So we resolve the "current"
     * tenant SOFTLY here — from the request subdomain, only when it matches one
     * of the user's memberships — and bind it so UserResource can flag
     * is_current and expose the plan. On non-tenant hosts this resolves to null
     * and the response simply has no current tenant / plan.
     */
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('tenants');

        $tenant = $this->resolveCurrentTenant($request, $user);

        if ($tenant !== null) {
            app()->instance('currentTenant', $tenant);
        }

        return new UserResource($user);
    }

    /**
     * Resolve the current tenant from the request subdomain, but only if the
     * authenticated user is actually a member of it. Returns null otherwise
     * (non-tenant host, reserved subdomain, or non-member).
     */
    private function resolveCurrentTenant(Request $request, User $user): ?Tenant
    {
        $label = explode('.', $request->getHost())[0] ?? '';

        if ($label === '') {
            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = $user->tenants->firstWhere('slug', $label);

        return $tenant;
    }
}
