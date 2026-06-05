<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\IssueTokenRequest;
use App\Modules\Auth\Http\Resources\TokenResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class TokenController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Issue a Bearer token (mobile / external client mode).
     *
     * The plaintext token is returned once and only once in `plain_text_token`.
     * The token model itself (without the plaintext) is also returned in `data`
     * so the client can store the token ID for later revocation.
     */
    public function issue(IssueTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! Auth::attempt(['email' => $validated['email'], 'password' => $validated['password']])) {
            // Same message for wrong password and unknown email — avoid user enumeration
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        $abilities = $validated['abilities'] ?? ['*'];
        $newToken = $this->authService->issueTokenFor($user, $validated['name'], $abilities);

        return (new TokenResource($newToken->accessToken))
            ->additional(['plain_text_token' => $newToken->plainTextToken])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * List all personal access tokens for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $tokens = $user->tokens()->orderByDesc('created_at')->get();

        return TokenResource::collection($tokens);
    }

    /**
     * Revoke a specific personal access token.
     *
     * Returns 404 (not 403) when the token does not belong to this user
     * to avoid revealing which token IDs exist globally.
     */
    public function revoke(Request $request, int $tokenId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $this->authService->revokeToken($user, $tokenId);

        if (! $revoked) {
            return response()->json([
                'message' => 'Resource not found.',
                'error_code' => 'resource.not_found',
                'meta' => ['request_id' => $request->header('X-Request-Id', '')],
            ], 404);
        }

        return response()->json(null, 204);
    }
}
