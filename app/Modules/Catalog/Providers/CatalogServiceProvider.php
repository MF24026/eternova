<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Policies\CategoryPolicy;
use App\Modules\Catalog\Repositories\CategoryRepositoryInterface;
use App\Modules\Catalog\Repositories\EloquentCategoryRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Category::class, CategoryPolicy::class);
    }
}
