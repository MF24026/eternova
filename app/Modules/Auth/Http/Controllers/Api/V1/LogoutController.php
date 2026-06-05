<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutController extends Controller
{
    /**
     * Log out the current user.
     *
     * Handles both SPA (session) and Bearer token authentication:
     *   - Revokes the current access token if the user authenticated via token.
     *   - Invalidates the session and regenerates the CSRF token for SPA mode.
     *
     * Returns 204 No Content — the client should clear its local auth state.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Revoke the current Sanctum token if request was token-authenticated.
        // TransientToken (used by actingAs() in tests and session-based SPA auth) has no
        // delete() method — only real PersonalAccessToken instances can be revoked.
        $currentToken = $request->user()?->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        }

        // Invalidate session (no-op if there is no session)
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }
}
