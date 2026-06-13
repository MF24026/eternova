<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Quotations\Models\Quotation;

/**
 * Gates quotation management operations to authenticated tenant members.
 *
 * Staff are included in all abilities because they need to:
 *   - viewAny: see the quotations list to track outstanding proposals
 *   - view: open a single quotation's detail for follow-up
 *   - create: capture new quotations during sales interactions
 *   - update: edit draft quotations, send, accept, or reject them
 *
 * delete is restricted to owner and admin — deleting a quotation is a
 * destructive action that should require elevated authority.
 */
final class QuotationPolicy extends TenantScopedPolicy
{
    /**
     * Any tenant staff member may list quotations.
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may view a single quotation.
     *
     * assertTenantMatches() also verifies the quotation belongs to the current
     * tenant — a cross-tenant quotation id resolves to null via BelongsToTenant
     * global scope and route-model binding, producing a natural 404 before
     * this policy is ever called. The check here is an additional safety net.
     */
    public function view(User $user, Quotation $quotation): bool
    {
        return $this->assertTenantMatches($user, $quotation)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may create a quotation.
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may update a quotation (edit, send, accept, reject).
     */
    public function update(User $user, Quotation $quotation): bool
    {
        return $this->assertTenantMatches($user, $quotation)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Only owner and admin may delete a quotation.
     */
    public function delete(User $user, Quotation $quotation): bool
    {
        return $this->assertTenantMatches($user, $quotation)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }
}
