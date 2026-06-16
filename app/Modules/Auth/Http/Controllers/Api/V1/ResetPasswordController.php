<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetPasswordController extends Controller
{
    /**
     * Consume a reset token and set the new password.
     *
     * On success the remember token is rotated (invalidating other sessions) and
     * a PasswordReset event fires. An invalid/expired token yields a 422 keyed on
     * the email field so the SPA can surface it inline.
     */
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            static function (User $user, string $password): void {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['El enlace de restablecimiento no es valido o ha expirado.'],
            ]);
        }

        return response()->json([
            'message' => 'Tu contrasena fue actualizada. Ya puedes iniciar sesion.',
        ]);
    }
}
