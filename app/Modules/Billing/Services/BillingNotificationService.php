<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Routes a billing notification to the tenant's owner. Prefers the owner User (so the in-app
 * database channel works); falls back to an on-demand mail to the tenant's billing email when
 * there is no owner user yet (e.g. mid-onboarding).
 *
 * Billing notifications are SaaS->tenant, so they use the default (Eternova) mail identity,
 * not the tenant's storefront brand.
 */
final class BillingNotificationService
{
    public function notifyTenantOwner(string $tenantId, Notification $notification): void
    {
        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            return;
        }

        $owner = $tenant->users()->wherePivot('role', 'owner')->first();

        if ($owner !== null) {
            $owner->notify($notification);

            return;
        }

        if (! empty($tenant->email)) {
            NotificationFacade::route('mail', $tenant->email)->notify($notification);
        }
    }
}
