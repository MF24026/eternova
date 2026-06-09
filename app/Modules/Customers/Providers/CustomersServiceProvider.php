<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Policies\CustomerPolicy;
use App\Modules\Customers\Repositories\CustomerRepositoryInterface;
use App\Modules\Customers\Repositories\EloquentCustomerRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class CustomersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerRepositoryInterface::class, EloquentCustomerRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}
