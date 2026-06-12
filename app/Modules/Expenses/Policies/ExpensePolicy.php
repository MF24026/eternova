<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Expenses\Models\Expense;

/**
 * Gates expense operations to authenticated tenant members.
 *
 * E3 supplies the minimal surface needed for receipt upload:
 *   - create: any staff member may upload a receipt / create an expense
 *   - view:   any staff member may view an expense and its OCR status
 *
 * E4 will extend this policy with update/delete/verify abilities when the
 * full expense management CRUD is built.
 */
final class ExpensePolicy extends TenantScopedPolicy
{
    /**
     * Any tenant staff member may create an expense (upload a receipt).
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may view an expense and poll its OCR status.
     *
     * assertTenantMatches() verifies the expense belongs to the current tenant.
     * Cross-tenant expense ids are naturally 404'd by route-model binding via
     * the BelongsToTenant global scope before this policy is ever called.
     */
    public function view(User $user, Expense $expense): bool
    {
        return $this->assertTenantMatches($user, $expense)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }
}
