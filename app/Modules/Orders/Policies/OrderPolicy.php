<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Orders\Models\Order;

/**
 * Gates order management operations to authenticated tenant members.
 *
 * Staff (cashiers) are included in all three abilities because they need to:
 *   - viewAny: see the orders list to track what they prepared/dispatched
 *   - view: open a single order's detail page for fulfilment or support
 *   - update: advance status, assign staff, or cancel orders during operations
 *
 * Only owners and admins can delete (soft-delete), which is a future ability
 * not exposed in the current API surface.
 */
final class OrderPolicy extends TenantScopedPolicy
{
    /**
     * Any tenant staff member may list orders.
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may view a single order.
     *
     * assertTenantMatches() also verifies the order belongs to the current
     * tenant — a cross-tenant order id resolves to null via BelongsToTenant
     * global scope and route-model binding, producing a natural 404 before
     * this policy is ever called. The check here is an additional safety net.
     */
    public function view(User $user, Order $order): bool
    {
        return $this->assertTenantMatches($user, $order)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may update an order (transition, assign, cancel).
     */
    public function update(User $user, Order $order): bool
    {
        return $this->assertTenantMatches($user, $order)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }
}
