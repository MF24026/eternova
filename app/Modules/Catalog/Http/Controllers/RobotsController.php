<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Returns a per-tenant robots.txt pointing crawlers at the sitemap.
 *
 * Server-rendered, declared in routes/web.php BEFORE the SPA catch-all.
 */
final class RobotsController extends Controller
{
    public function index(Request $request): Response
    {
        $sitemapUrl = $request->getScheme().'://'.$request->getHost().'/sitemap.xml';

        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Sitemap: '.$sitemapUrl,
        ]);

        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
