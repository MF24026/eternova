<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const ALLOWED_PROVIDERS = ['google'];

    /**
     * Redirect to the OAuth provider.
     */
    public function redirect(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, strict: true)) {
            abort(404);
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the OAuth provider callback.
     *
     * Finds or creates a user from the social profile, associates them
     * with the demo tenant by default, and logs them in.
     */
    public function callback(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, strict: true)) {
            abort(404);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            Log::warning('Social auth callback failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'No se pudo autenticar con ' . ucfirst($provider) . '. Intenta de nuevo.',
            ]);
        }

        /** @var User|null $user */
        $user = User::where('email', $socialUser->getEmail())->first();

        if ($user !== null) {
            $user->update([
                'provider'    => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar'      => $user->avatar ?? $socialUser->getAvatar(),
            ]);

            Log::info('Social auth: existing user logged in', [
                'user_id'  => $user->id,
                'provider' => $provider,
            ]);
        } else {
            $demoTenant = Tenant::findBySlug('demo');

            $user = User::create([
                'tenant_id'   => $demoTenant?->id,
                'name'        => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Usuario',
                'email'       => $socialUser->getEmail(),
                'password'    => null,
                'avatar'      => $socialUser->getAvatar(),
                'provider'    => $provider,
                'provider_id' => $socialUser->getId(),
                'role'        => 'customer',
            ]);

            Log::info('Social auth: new user created', [
                'user_id'    => $user->id,
                'provider'   => $provider,
                'tenant_id'  => $user->tenant_id,
            ]);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('admin.dashboard'));
    }
}
