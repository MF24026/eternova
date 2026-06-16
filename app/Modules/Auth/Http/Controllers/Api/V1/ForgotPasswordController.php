<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

final class ForgotPasswordController extends Controller
{
    /**
     * Send a password reset link to the given email.
     *
     * The response is intentionally identical whether or not the email exists,
     * to avoid leaking which addresses have accounts (user enumeration). The one
     * exception is throttling, which returns 429 so the user knows to wait.
     */
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Espera unos momentos antes de solicitar otro enlace.',
            ], 429);
        }

        // PASSWORD_RESET_LINK_SENT and INVALID_USER both return the same message.
        return response()->json([
            'message' => 'Si el correo esta registrado, enviamos un enlace para restablecer la contrasena.',
        ]);
    }
}
