<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Generates a per-tenant XML sitemap for the public storefront.
 *
 * This is a server-rendered response (NOT a SPA route). It must be declared
 * in routes/web.php BEFORE the SPA catch-all so Laravel handles it directly.
 *
 * Only active products belonging to the current tenant are listed.
 * The BelongsToTenant global scope + is_active filter guarantees isolation.
 */
final class SitemapController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Tenant $tenant */
        $tenant = app('currentTenant');

        $baseUrl = $request->getScheme().'://'.$request->getHost();

        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('updated_at', 'desc')
            ->get(['slug', 'updated_at']);

        $xml = view('storefront.sitemap', [
            'baseUrl' => $baseUrl,
            'products' => $products,
        ])->render();

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
