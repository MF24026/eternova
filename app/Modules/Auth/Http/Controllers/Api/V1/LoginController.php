<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Login via session cookie (SPA mode).
     *
     * After a successful login the SPA's Axios with withCredentials: true
     * automatically sends the session cookie on subsequent requests. The
     * response body contains the user resource so the SPA can hydrate its store.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! Auth::attempt(['email' => $validated['email'], 'password' => $validated['password']])) {
            // Use the same message for wrong password and unknown email
            // to prevent user enumeration via distinct error messages.
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // Regenerate session only when a session store is available (SPA mode).
        // API routes running without the web middleware stack have no session.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = Auth::user();
        $user->load('tenants');

        return (new UserResource($user))->response()->setStatusCode(200);
    }
}
