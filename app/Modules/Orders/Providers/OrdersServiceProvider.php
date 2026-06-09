<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Repositories\EloquentOrderRepository;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use Illuminate\Support\ServiceProvider;

final class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
    }
}
