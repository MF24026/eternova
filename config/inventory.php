<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default minimum stock alert threshold
    |--------------------------------------------------------------------------
    |
    | When a ProductVariant has no explicit min_stock_alert set (NULL), this
    | global default is used. Individual tenants may override this in their
    | locale_extra JSON field ('default_min_stock' key).
    |
    */
    'default_min_stock' => (int) env('INVENTORY_DEFAULT_MIN_STOCK', 10),
];
