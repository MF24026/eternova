<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\POS\Providers\PosServiceProvider;
use App\Modules\Expenses\Providers\ExpensesServiceProvider;
use App\Modules\Reservations\Providers\ReservationsServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    OrdersServiceProvider::class,
    ExpensesServiceProvider::class,
    ReservationsServiceProvider::class,
    CustomersServiceProvider::class,
    PosServiceProvider::class,
];
