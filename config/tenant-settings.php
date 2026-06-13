<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant Settings — execution groups stored in `branch_settings`
|--------------------------------------------------------------------------
|
| Catalog of the per-branch-aware EXECUTION settings (group => key => coded
| default). The lenient resolver (BranchSetting::resolvedGroup) layers these
| beneath the tenant default rows and any per-branch overrides:
|
|   coded default  <-  tenant default row (branch_id = null)  <-  branch override
|
| The `defaults` keys are also the ALLOW-LIST for writes: BranchSetting::writeDefault
| silently ignores any key not declared here, so the API can never persist arbitrary
| keys. POLICY settings (brand, locale, quotation/reservation defaults) are NOT here —
| they live on the `tenants` row.
|
*/

return [

    // Group identifiers exposed by the Settings module (drives tabs + validation).
    'groups' => ['contact', 'tax', 'orders', 'notifications'],

    'defaults' => [

        // Contact — phone/email/address. Per-branch in spirit (each location has its own).
        'contact' => [
            'phone'   => null,
            'email'   => null,
            'website' => null,
            'address' => null,
        ],

        // Tax — IVA toggle + rate (basis points) + local tax-id label/number.
        'tax' => [
            'enabled'   => true,
            'rate_bps'  => 1300,   // 13% IVA (El Salvador default)
            'id_label'  => null,   // e.g. 'NIT' (SV), 'RFC' (MX), 'NIT' (CO)
            'id_number' => null,
        ],

        // Orders — operational defaults for the order workflow.
        'orders' => [
            'auto_confirm'         => false,
            'default_prep_minutes' => 60,
            'pending_alert_hours'  => 4,
        ],

        // Notifications — per-event alert toggles.
        'notifications' => [
            'new_order'              => true,
            'order_pending'          => true,
            'low_stock'              => true,
            'reservation_confirmed'  => false,
            'quotation_accepted'     => true,
            'payment_received'       => true,
        ],
    ],
];
