<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the dashboard gate ability.
 *
 * The dashboard aggregates tenant-wide figures (sales, expenses, pending orders,
 * recent orders with customer names) and has no Eloquent model, so we register a
 * named gate rather than a policy class — same approach as the POS abilities.
 *
 *   - dashboard.view — read the tenant KPI summary
 *
 * The gate keys off currentRole(), which is null for users who are not members
 * of the resolved tenant. That is what stops an authenticated user from reading
 * a DIFFERENT tenant's dashboard, and what excludes the read-only 'customer'
 * role — the controller previously had no authorization at all.
 */
final class DashboardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $staffRoles = ['owner', 'admin', 'staff'];

        Gate::define('dashboard.view', static function (User $user) use ($staffRoles): bool {
            if ($user->is_super_admin) {
                return true;
            }

            $role = $user->currentRole();

            return $role !== null && in_array($role, $staffRoles, strict: true);
        });
    }
}
