<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Hard gate for the SaaS operator console (admin.eternova.app). Access is granted ONLY to users
 * with the dedicated `is_super_admin` boolean — never via a tenant RBAC role. This is a separate
 * trust axis from the per-tenant owner/admin/staff roles, so a tenant can never escalate into
 * platform admin.
 *
 * Defense in depth: this middleware is the real boundary (the host admin.eternova.app is just
 * routing, never trusted). Every reached super-admin request is logged to the billing channel
 * for accountability — who, from where, hit what.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_super_admin) {
            // Record the denied attempt — a non-super-admin probing these routes is a signal.
            Log::channel('billing')->warning('superadmin_access_denied', [
                'user_id' => $user?->id,
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            // AccessDeniedHttpException has a dedicated 403 renderer in this app; a bare
            // abort(403) would fall through to the generic Throwable->500 handler.
            throw new AccessDeniedHttpException('Super-admin access required.');
        }

        Log::channel('billing')->info('superadmin_access', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        return $next($request);
    }
}
