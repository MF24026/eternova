<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;

/**
 * Handles user authentication lifecycle: registration, token issuance, token revocation.
 *
 * This service knows nothing about HTTP. Controllers pass validated data to it;
 * it returns domain objects (User, NewAccessToken) or throws domain exceptions.
 */
final readonly class AuthService
{
    /**
     * Create a new global user account and fire the Registered event.
     *
     * Fires the Registered event so Laravel's default email verification
     * notification is dispatched automatically.
     */
    public function register(string $name, string $email, string $password): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_super_admin' => false,
        ]);

        Log::info('User registered', ['user_id' => $user->id, 'email' => $user->email]);

        event(new Registered($user));

        return $user;
    }

    /**
     * Issue a Sanctum personal access token for the given user.
     *
     * The plaintext token returned by this method is ONLY available at this moment.
     * Store it (or show it) immediately — it cannot be recovered later.
     *
     * @param  list<string>  $abilities
     */
    public function issueTokenFor(User $user, string $name, array $abilities = ['*']): NewAccessToken
    {
        $token = $user->createToken(name: $name, abilities: $abilities);

        Log::info('Personal access token issued', [
            'user_id' => $user->id,
            'token_id' => $token->accessToken->id,
            'name' => $name,
        ]);

        return $token;
    }

    /**
     * Revoke a specific personal access token belonging to the given user.
     *
     * Returns false (instead of throwing) when the token does not exist or belongs
     * to a different user — callers should translate this to a 404, not a 403,
     * to avoid leaking which tokens exist.
     */
    public function revokeToken(User $user, int $tokenId): bool
    {
        $deleted = $user->tokens()
            ->where('id', $tokenId)
            ->delete();

        if ($deleted > 0) {
            Log::info('Personal access token revoked', [
                'user_id' => $user->id,
                'token_id' => $tokenId,
            ]);
        }

        return $deleted > 0;
    }
}
