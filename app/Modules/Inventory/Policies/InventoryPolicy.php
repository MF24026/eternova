<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Inventory\Models\BranchInventory;

final class InventoryPolicy extends TenantScopedPolicy
{
    /**
     * Any authenticated tenant member can list or view stock.
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any authenticated tenant member can view a single stock row.
     */
    public function view(User $user, BranchInventory $inventory): bool
    {
        return $this->assertTenantMatches($user, $inventory);
    }

    /**
     * Entries and exits can be recorded by staff and above.
     */
    public function recordMovement(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Transfers can be triggered by staff and above.
     */
    public function transfer(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Manual adjustments are restricted to owner/admin — staff cannot directly
     * override stock counts, which would bypass the normal entry/exit audit trail.
     */
    public function adjust(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }
}
