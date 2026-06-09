<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    OrdersServiceProvider::class,
    CustomersServiceProvider::class,
];
