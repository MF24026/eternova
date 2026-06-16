<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches defensive response headers to every request.
 *
 * The static headers (nosniff, frame-options, referrer-policy, permissions-policy)
 * are cheap, universally safe and always sent. HSTS and CSP are conditional:
 *
 *   - HSTS is only emitted over real TLS. Once a browser sees it, it refuses
 *     http for max-age — sending it on plain-http dev would brick localhost.
 *   - CSP is strict in production (per-request Vite nonce, no inline scripts)
 *     and relaxed in local/CI, where Debugbar injects inline scripts and the
 *     Vite dev client needs eval. This mirrors the rate-limit posture: secure
 *     by default in production, relaxed via config where dev tooling needs it.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');

        if ($request->secure() && config('security.headers.hsts_enabled')) {
            $maxAge = (int) config('security.headers.hsts_max_age', 31536000);
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains; preload");
        }

        if (config('security.headers.csp_enabled')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * Build the Content-Security-Policy string.
     *
     * Scripts are the only directive that differs by environment. In production
     * we allow only same-origin scripts plus the per-request nonce that Laravel's
     * Vite integration stamps onto the script tags it renders, so an injected
     * inline <script> cannot execute. In non-production we fall back to
     * 'unsafe-inline'/'unsafe-eval' so Debugbar and the Vite dev client work.
     *
     * Styles keep 'unsafe-inline' in every environment: Vue injects runtime
     * <style> blocks and :style bindings that carry no nonce, so a style nonce
     * would break the UI.
     *
     * Fonts come from two CDNs today: bunny.net (the <link> in the blade shell)
     * and Google Fonts (an @import in app.css that reaches fonts.googleapis.com
     * for the stylesheet and fonts.gstatic.com for the files). Both are
     * whitelisted so the policy matches what the app actually loads; collapsing
     * onto a single provider is a separate cleanup.
     */
    private function contentSecurityPolicy(): string
    {
        $scriptSrc = app()->isProduction()
            ? "'self' 'nonce-" . Vite::cspNonce() . "'"
            : "'self' 'unsafe-inline' 'unsafe-eval'";

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data: blob:",
            "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "script-src {$scriptSrc}",
            "connect-src 'self'",
        ]);
    }
}
