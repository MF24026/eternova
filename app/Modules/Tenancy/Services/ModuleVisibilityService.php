<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Resolves which OPTIONAL (gateable) modules a tenant sees, composing the giro's
 * catalog defaults with the tenant's explicit per-module overrides. This is the ONLY
 * place that answers module visibility for verticals; business modules stay ignorant
 * of it. Plan-gating (usePlanGate) is a separate concern and not touched here.
 */
final class ModuleVisibilityService
{
    /** @var list<string> */
    public const GATEABLE = ['reservations', 'quotations', 'cash_register'];

    /**
     * @return list<string> enabled gateable modules for the tenant
     */
    public function enabledModules(Tenant $tenant): array
    {
        $catalog = (array) config('verticals.catalog', []);
        $defaults = (array) ($catalog[$tenant->business_type]['modules'] ?? []);
        $overrides = (array) ($tenant->module_overrides ?? []);

        return array_values(array_filter(self::GATEABLE, static function (string $module) use ($defaults, $overrides): bool {
            return array_key_exists($module, $overrides)
                ? (bool) $overrides[$module]
                : in_array($module, $defaults, true);
        }));
    }

    public function isEnabled(Tenant $tenant, string $module): bool
    {
        // A non-gateable (core) module is always enabled.
        if (! in_array($module, self::GATEABLE, true)) {
            return true;
        }

        return in_array($module, $this->enabledModules($tenant), true);
    }
}
