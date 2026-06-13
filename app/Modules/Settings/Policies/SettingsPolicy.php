<?php

declare(strict_types=1);

namespace App\Modules\Settings\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;

/**
 * Authorizes access to the tenant Settings module.
 *
 * Settings is a singleton resource (the tenant's own configuration), so these
 * abilities take only the User — there is no model instance. They are wired as
 * gates ('settings.view' / 'settings.manage') in SettingsServiceProvider.
 *
 * Both reading and managing settings are restricted to owner + admin: settings
 * carry tenant-wide policy (brand, currency, tax) that staff should not alter.
 * super-admin is granted via TenantScopedPolicy::before().
 */
final class SettingsPolicy extends TenantScopedPolicy
{
    public function view(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }

    public function manage(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }
}
