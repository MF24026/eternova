<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
];
