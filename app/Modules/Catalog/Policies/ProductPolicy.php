<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Catalog\Models\Product;

final class ProductPolicy extends TenantScopedPolicy
{
    /**
     * Any authenticated tenant member may list products.
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any authenticated tenant member may view a single product.
     */
    public function view(User $user, Product $product): bool
    {
        return $this->assertTenantMatches($user, $product);
    }

    /**
     * Only owners and admins may create products.
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may update products belonging to their tenant.
     */
    public function update(User $user, Product $product): bool
    {
        return $this->assertTenantMatches($user, $product)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may soft-delete products.
     */
    public function delete(User $user, Product $product): bool
    {
        return $this->assertTenantMatches($user, $product)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owners and admins may restore soft-deleted products.
     */
    public function restore(User $user, Product $product): bool
    {
        return $this->assertTenantMatches($user, $product)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }
}
