<?php

declare(strict_types=1);

use App\Exceptions\PlanGateException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InjectRequestId;
use App\Modules\Tenancy\Http\Middleware\EnsureTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            // Load every routes/api/v1/*.php file under the /api/v1 prefix.
            Route::middleware(['api', InjectRequestId::class])
                ->prefix('api/v1')
                ->group(static function (): void {
                    foreach (glob(__DIR__.'/../routes/api/v1/*.php') as $file) {
                        require $file;
                    }
                });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
            HandleInertiaRequests::class,
        ]);

        // Register alias — apply to tenant-scoped routes when ready
        $middleware->alias([
            'tenant' => EnsureTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Only return JSON when the request expects it (API routes or explicit Accept header).
        $shouldRespondJson = static fn (Request $request): bool => $request->is('api/*') || $request->wantsJson();

        // ── 422 Validation ────────────────────────────────────────────────────
        $exceptions->render(static function (ValidationException $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 422);
        });

        // ── 401 Unauthenticated ───────────────────────────────────────────────
        $exceptions->render(static function (AuthenticationException $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Unauthenticated.',
                'error_code' => 'auth.unauthenticated',
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 401);
        });

        // ── 403 Forbidden ─────────────────────────────────────────────────────
        $exceptions->render(static function (AuthorizationException $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'This action is unauthorized.',
                'error_code' => 'auth.forbidden',
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 403);
        });

        // ── 404 Model not found ───────────────────────────────────────────────
        $exceptions->render(static function (ModelNotFoundException $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found.',
                'error_code' => 'resource.not_found',
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 404);
        });

        // ── 404 Route not found ───────────────────────────────────────────────
        $exceptions->render(static function (NotFoundHttpException $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            // When route-model binding fails, Laravel wraps ModelNotFoundException in a
            // NotFoundHttpException. Detect that case and return resource.not_found so
            // the 404 body is consistent regardless of whether the miss comes from model
            // binding or an explicit findOrFail() call.
            if ($e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => 'Resource not found.',
                    'error_code' => 'resource.not_found',
                    'meta' => ['request_id' => $request->header('X-Request-Id', '')],
                ], 404);
            }

            return response()->json([
                'message' => 'Route not found.',
                'error_code' => 'route.not_found',
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 404);
        });

        // ── 429 Rate limit ────────────────────────────────────────────────────
        $exceptions->render(static function (TooManyRequestsHttpException $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Too many requests.',
                'error_code' => 'rate_limit.exceeded',
                'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 429);
        });

        // ── 402 Plan gate ─────────────────────────────────────────────────────
        $exceptions->render(static function (PlanGateException $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'plan.feature_locked',
                'feature' => $e->feature,
                'current_plan' => $e->currentPlan,
                'required_plan' => $e->requiredPlan,
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 402);
        });

        // ── 500 Generic fallback (API routes only) ────────────────────────────
        $exceptions->render(static function (Throwable $e, Request $request) use ($shouldRespondJson) {
            if (! $shouldRespondJson($request)) {
                return null;
            }

            $body = [
                'message' => 'An unexpected error occurred.',
                'error_code' => 'server.error',
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ];

            if (! app()->environment('production')) {
                $body['debug'] = [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => collect($e->getTrace())->take(10)->all(),
                ];
            }

            return response()->json($body, 500);
        });
    })->create();
