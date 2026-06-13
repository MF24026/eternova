<?php

declare(strict_types=1);

namespace App\Modules\Settings\Providers;

use App\Modules\Settings\Policies\SettingsPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Settings module.
 *
 * Settings is a singleton resource (no model), so authorization is exposed as
 * gates rather than a Gate::policy(Model) binding:
 *   - 'settings.view'   → SettingsPolicy::view   (owner/admin may read)
 *   - 'settings.manage' → SettingsPolicy::manage (owner/admin may write)
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('settings.view', [SettingsPolicy::class, 'view']);
        Gate::define('settings.manage', [SettingsPolicy::class, 'manage']);
    }
}
