<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Storefront Routes (Public)
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => Inertia::render('Storefront/Home'))->name('home');

Route::get('/catalog/{slug?}', function (?string $slug = null) {
    return Inertia::render('Storefront/Catalog', ['slug' => $slug]);
})->name('storefront.catalog');

Route::get('/product/{slug}', function (string $slug) {
    return Inertia::render('Storefront/ProductDetail', ['slug' => $slug]);
})->name('storefront.product');

Route::get('/checkout', fn () => Inertia::render('Storefront/Checkout'))->name('storefront.checkout');

Route::get('/track', function () {
    return Inertia::render('Storefront/OrderTracking', ['order' => request('order')]);
})->name('storefront.tracking');

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function (): void {
    // TODO: Add auth + tenant middleware after Breeze setup
    Route::get('/dashboard', fn () => Inertia::render('Admin/Dashboard'))->name('dashboard');
    Route::get('/pos', fn () => Inertia::render('Admin/POS'))->name('pos');
    Route::get('/orders', fn () => Inertia::render('Admin/Orders'))->name('orders');
    Route::get('/reservations', fn () => Inertia::render('Admin/Reservations'))->name('reservations');
    Route::get('/inventory', fn () => Inertia::render('Admin/Inventory'))->name('inventory');
    Route::get('/products', fn () => Inertia::render('Admin/Products'))->name('products');
    Route::get('/categories', fn () => Inertia::render('Admin/Categories'))->name('categories');
    Route::get('/expenses', fn () => Inertia::render('Admin/Expenses'))->name('expenses');
    Route::get('/quotations', fn () => Inertia::render('Admin/Quotations'))->name('quotations');
    Route::get('/customers', fn () => Inertia::render('Admin/Customers'))->name('customers');
    Route::get('/settings', fn () => Inertia::render('Admin/Settings'))->name('settings');
});

require __DIR__.'/auth.php';
