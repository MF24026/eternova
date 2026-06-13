<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Providers;

use App\Modules\Quotations\Repositories\EloquentQuotationRepository;
use App\Modules\Quotations\Repositories\QuotationRepositoryInterface;
use Illuminate\Support\ServiceProvider;

final class QuotationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuotationRepositoryInterface::class, EloquentQuotationRepository::class);
    }
}
