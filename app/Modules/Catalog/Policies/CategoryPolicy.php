<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Catalog\Models\Category;

final class CategoryPolicy extends TenantScopedPolicy
{
    /**
     * Any authenticated tenant member may list categories.
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any authenticated tenant member may view a single category.
     */
    public function view(User $user, Category $category): bool
    {
        return $this->assertTenantMatches($user, $category);
    }

    /**
     * Only owners and admins may create categories.
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may update categories belonging to their tenant.
     */
    public function update(User $user, Category $category): bool
    {
        return $this->assertTenantMatches($user, $category)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may soft-delete categories belonging to their tenant.
     */
    public function delete(User $user, Category $category): bool
    {
        return $this->assertTenantMatches($user, $category)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may restore soft-deleted categories.
     */
    public function restore(User $user, Category $category): bool
    {
        return $this->assertTenantMatches($user, $category)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may bulk-reorder categories.
     */
    public function reorder(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }
}
