<?php

declare(strict_types=1);

namespace App\Modules\POS\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;

/**
 * Gates POS operations to authenticated tenant members with a staff-level role or above.
 *
 * The POS terminal is an internal operational tool — customers (external actors)
 * never receive this role and are therefore rejected by assertHasRole.
 */
final class PosPolicy extends TenantScopedPolicy
{
    /**
     * Operate the POS product grid (browse products + stock).
     *
     * Staff are included because cashiers need to look up products.
     */
    public function use(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Perform a checkout (create order + deduct inventory).
     */
    public function checkout(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * View receipt data for an order.
     */
    public function viewReceipt(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }
}
