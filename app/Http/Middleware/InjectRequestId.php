<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propagates or generates an X-Request-Id header for every API request.
 *
 * If the incoming request already carries an X-Request-Id header, that value is
 * reused so distributed traces stay correlated. Otherwise a fresh UUID v4 is
 * generated. The resolved ID is written back onto the request so that it can be
 * read by controllers, resources and the exception handler without re-parsing
 * the header, and it is also appended to every outgoing response.
 */
final class InjectRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?? (string) Str::uuid();

        // Persist on the request instance so downstream code can read it cleanly.
        $request->headers->set('X-Request-Id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
