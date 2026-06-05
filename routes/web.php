<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA Catch-All
|--------------------------------------------------------------------------
| All web routes (except /api/*) are handled by the Vue 3 SPA.
| Vue Router controls navigation client-side after the initial load.
|
| The old Inertia-based routes (/admin/*, /catalog/*, /product/*, etc.)
| are intentionally removed. They will be handled by Vue Router in the SPA.
| Backend API routes live under /api/v1/* (see routes/api/).
| Legacy Inertia pages in resources/js/Pages/ (capital P) remain dormant
| until issue #12 migrates or removes them.
*/

Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api).*$')
    ->name('spa');
