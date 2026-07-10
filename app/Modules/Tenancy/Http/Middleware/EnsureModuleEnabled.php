<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Services\ModuleVisibilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Refuses a request when the current tenant has the named optional module disabled
 * for its business vertical. Runs after the `tenant` middleware, so current_tenant()
 * is resolved. Server-side twin of the nav gating: a disabled module is unreachable
 * via the API, not merely hidden.
 *
 * Throws AccessDeniedHttpException (not abort(403)): a bare HttpException raised from
 * a middleware renders as 500 through this app's exception handler, so we raise the
 * typed exception the same way EnsureSuperAdmin does to get a real 403.
 */
final class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleVisibilityService $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = current_tenant();

        if ($tenant !== null && ! $this->modules->isEnabled($tenant, $module)) {
            throw new AccessDeniedHttpException("El módulo '{$module}' no está habilitado para este negocio.");
        }

        return $next($request);
    }
}
