<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers POS gate abilities.
 *
 * POS actions have no associated Eloquent model — we register named gate
 * abilities directly via Gate::define rather than a policy class. This avoids
 * the Gate::policy(Foo::class, Foo::class) self-referential workaround.
 *
 * Abilities:
 *   - pos.use      — browse the product grid and stock
 *   - pos.checkout — create an order and deduct inventory
 *   - pos.receipt  — view receipt data for any order in this tenant
 */
final class PosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $staffRoles = ['owner', 'admin', 'staff'];

        Gate::define('pos.use', static function (User $user) use ($staffRoles): bool {
            if ($user->is_super_admin) {
                return true;
            }

            $role = $user->currentRole();

            return $role !== null && in_array($role, $staffRoles, strict: true);
        });

        Gate::define('pos.checkout', static function (User $user) use ($staffRoles): bool {
            if ($user->is_super_admin) {
                return true;
            }

            $role = $user->currentRole();

            return $role !== null && in_array($role, $staffRoles, strict: true);
        });

        Gate::define('pos.receipt', static function (User $user) use ($staffRoles): bool {
            if ($user->is_super_admin) {
                return true;
            }

            $role = $user->currentRole();

            return $role !== null && in_array($role, $staffRoles, strict: true);
        });
    }
}
