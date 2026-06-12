<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Expenses\Models\Expense;

/**
 * Gates expense operations to authenticated tenant members.
 *
 * Role split:
 *   - owner|admin|staff : viewAny, view, create, update
 *   - owner|admin only  : delete, manageCategories
 *
 * Staff should be able to enter and correct expenses (they're often the ones
 * doing it). Deletion and category configuration are restricted to managers
 * because they affect accounting history.
 */
final class ExpensePolicy extends TenantScopedPolicy
{
    /**
     * Any tenant staff member may list expenses.
     */
    public function viewAny(User $user): bool
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

    /**
     * Any tenant staff member may create an expense (upload a receipt or manual entry).
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may update an expense, including verifying a draft.
     *
     * This covers the "verify" path: staff review OCR suggestions, correct fields,
     * and set is_verified=true. The update and verify flows share this single ability
     * because the HTTP action is the same (PATCH /{expense}).
     */
    public function update(User $user, Expense $expense): bool
    {
        return $this->assertTenantMatches($user, $expense)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Only owner and admin may permanently remove an expense record.
     *
     * Deletion affects accounting history and is restricted to managers.
     */
    public function delete(User $user, Expense $expense): bool
    {
        return $this->assertTenantMatches($user, $expense)
            && $this->assertHasRole($user, ['owner', 'admin']);
    }

    /**
     * Only owner and admin may manage expense categories (create, rename, retire).
     *
     * Categories affect how all expenses are classified — tenant-level configuration
     * that staff should not change unilaterally.
     */
    public function manageCategories(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }
}
