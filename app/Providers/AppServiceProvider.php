<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Password-reset links must point at the SPA reset page on the SAME host
        // the request came from (each tenant lives on its own subdomain), not at
        // a backend web route. The plaintext token only ever travels in the email.
        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            $host = request()?->getSchemeAndHttpHost() ?? config('app.url');
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : $notifiable->getAttribute('email');

            return $host . '/reset-password?token=' . $token . '&email=' . urlencode((string) $email);
        });
    }
}
