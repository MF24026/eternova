<?php

declare(strict_types=1);

namespace App\Modules\Auth\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base for policies that operate on tenant-scoped resources.
 *
 * Concrete policies extend this class and call assertTenantMatches() and/or
 * assertHasRole() to enforce the two primary authorization axes:
 *
 *   1. The resource belongs to the same tenant the request is scoped to.
 *   2. The authenticated user has a sufficient role in that tenant.
 *
 * Both checks operate on the *current* tenant context (from current_tenant()).
 * If there is no active tenant context, both checks return false — this is
 * intentional: platform-level routes should not use tenant-scoped policies.
 */
abstract class TenantScopedPolicy
{
    /**
     * Super-admin bypasses all policy checks.
     *
     * Returning true from before() grants the ability immediately without
     * calling the specific ability method. This is correct for super-admins
     * who operate across all tenants from the /super-admin/* panel.
     *
     * Returning null defers to the specific ability method for all other users.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return null;
    }

    /**
     * Verify the resource belongs to the current tenant AND the user is a member.
     *
     * Returns false when:
     *  - No active tenant context
     *  - Resource tenant_id does not match the current tenant
     *  - User has no role in the current tenant
     */
    protected function assertTenantMatches(User $user, Model $resource): bool
    {
        $tenant = current_tenant();

        if ($tenant === null) {
            return false;
        }

        // The resource must belong to the same tenant as the request context
        if (! isset($resource->tenant_id) || $resource->tenant_id !== $tenant->id) {
            return false;
        }

        // The user must be a member of this tenant
        return $user->currentRole() !== null;
    }

    /**
     * Verify the user has one of the allowed roles in the current tenant.
     *
     * @param  string|list<string>  $allowedRoles
     */
    protected function assertHasRole(User $user, string|array $allowedRoles): bool
    {
        if (current_tenant() === null) {
            return false;
        }

        $role = $user->currentRole();

        if ($role === null) {
            return false;
        }

        $allowed = is_string($allowedRoles) ? [$allowedRoles] : $allowedRoles;

        return in_array($role, $allowed, strict: true);
    }
}
