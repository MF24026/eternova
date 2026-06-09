<?php

declare(strict_types=1);

namespace App\Modules\Customers\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Customers\Models\Customer;

final class CustomerPolicy extends TenantScopedPolicy
{
    /**
     * Any authenticated tenant member may list customers (needed for POS selector).
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any authenticated tenant member may view a single customer.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $this->assertTenantMatches($user, $customer);
    }

    /**
     * Staff CAN create customers — required for POS workflows where a cashier
     * registers a new walk-in customer during checkout.
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Staff CAN update customers — same POS rationale as create.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $this->assertTenantMatches($user, $customer)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Only owners and admins may soft-delete customers.
     * Staff should not accidentally wipe customer history from the POS.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $this->assertTenantMatches($user, $customer)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may restore soft-deleted customers.
     */
    public function restore(User $user, Customer $customer): bool
    {
        return $this->assertTenantMatches($user, $customer)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }
}
