<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Providers;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Policies\QuotationPolicy;
use App\Modules\Quotations\Repositories\EloquentQuotationRepository;
use App\Modules\Quotations\Repositories\QuotationRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class QuotationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuotationRepositoryInterface::class, EloquentQuotationRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Quotation::class, QuotationPolicy::class);
    }
}
