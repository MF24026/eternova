<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;

final class RegisterController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Register a new global user account.
     *
     * Creates the user, fires the Registered event (triggers email verification),
     * and immediately issues a Bearer token so the SPA can proceed to onboarding
     * without a second auth round-trip.
     *
     * Note: this endpoint does NOT create a tenant. Tenant creation happens via
     * POST /api/v1/tenants (issue #13 wizard flow). A fresh user has zero tenants.
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->authService->register(
            name: $validated['name'],
            email: $validated['email'],
            password: $validated['password'],
        );

        $token = $this->authService->issueTokenFor(
            user: $user,
            name: $request->userAgent() ?? 'web',
        );

        // Load tenants eagerly so UserResource has the relationship available.
        // A freshly registered user has zero tenants, but we load for consistency.
        $user->load('tenants');

        return (new UserResource($user))
            ->additional(['plain_text_token' => $token->plainTextToken])
            ->response()
            ->setStatusCode(201);
    }
}
