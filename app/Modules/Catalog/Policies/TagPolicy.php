<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Catalog\Models\Tag;

final class TagPolicy extends TenantScopedPolicy
{
    /**
     * Any authenticated tenant member may list tags.
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Only owners and admins may create tags.
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may update tags belonging to their tenant.
     */
    public function update(User $user, Tag $tag): bool
    {
        return $this->assertTenantMatches($user, $tag)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may delete tags.
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $this->assertTenantMatches($user, $tag)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }
}
