<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The billing maintenance switch. When enabled, the billing crons skip their run and the
 * webhook processing can be paused — used during DB migrations, gateway provider maintenance,
 * or a security incident, so we never charge/suspend/delete mid-operation.
 *
 * The full enable/disable/status CLI + UI banner land in Phase 8; this is the shared flag
 * the crons consult from day one so the guard is in place before it is needed.
 */
final class BillingMaintenance
{
    private const KEY = 'billing:maintenance';

    public static function isEnabled(): bool
    {
        return (bool) Cache::get(self::KEY, false);
    }

    public static function enable(): void
    {
        Cache::forever(self::KEY, true);
    }

    public static function disable(): void
    {
        Cache::forget(self::KEY);
    }
}
