<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Policies\CategoryPolicy;
use App\Modules\Catalog\Policies\ProductPolicy;
use App\Modules\Catalog\Policies\TagPolicy;
use App\Modules\Catalog\Repositories\CategoryRepositoryInterface;
use App\Modules\Catalog\Repositories\EloquentCategoryRepository;
use App\Modules\Catalog\Repositories\EloquentProductRepository;
use App\Modules\Catalog\Repositories\EloquentTagRepository;
use App\Modules\Catalog\Repositories\ProductRepositoryInterface;
use App\Modules\Catalog\Repositories\TagRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(TagRepositoryInterface::class, EloquentTagRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);
    }
}
