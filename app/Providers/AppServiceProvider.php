<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
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

        // Password-reset email: link points at the SPA reset page on the same
        // host the request came from (each tenant lives on its own subdomain),
        // and the message is in Spanish, branded with the tenant's business name.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token): string => $this->resetPasswordUrl($notifiable, $token));
        ResetPassword::toMailUsing(fn (object $notifiable, string $token): MailMessage => $this->resetPasswordMail($notifiable, $token));
    }

    /**
     * The SPA reset URL. The plaintext token only ever travels in the email.
     */
    private function resetPasswordUrl(object $notifiable, string $token): string
    {
        $host = request()?->getSchemeAndHttpHost() ?? (string) config('app.url');
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : $notifiable->getAttribute('email');

        return $host . '/reset-password?token=' . $token . '&email=' . urlencode((string) $email);
    }

    /**
     * The brand shown in the reset email: the tenant's business name when the
     * request resolves to a tenant subdomain, otherwise the SaaS name.
     */
    private function resetPasswordBrand(object $notifiable): string
    {
        $fallback = (string) (config('app.name') ?: 'Eternova');
        $host = request()?->getHost();

        if ($host === null || ! $notifiable instanceof User) {
            return $fallback;
        }

        $label = explode('.', $host)[0];
        $tenant = $notifiable->tenants()->where('slug', $label)->first();

        return $tenant?->business_name ?: $fallback;
    }

    /**
     * The Spanish, branded reset-password mail.
     */
    private function resetPasswordMail(object $notifiable, string $token): MailMessage
    {
        $brand  = $this->resetPasswordBrand($notifiable);
        $broker = (string) config('auth.defaults.passwords', 'users');
        $expire = (int) config("auth.passwords.{$broker}.expire", 60);

        return (new MailMessage())
            ->subject("Restablece tu contrasena en {$brand}")
            ->greeting('Hola,')
            ->line("Recibimos una solicitud para restablecer la contrasena de tu cuenta en {$brand}.")
            ->action('Restablecer contrasena', $this->resetPasswordUrl($notifiable, $token))
            ->line("Este enlace caduca en {$expire} minutos.")
            ->line('Si no solicitaste este cambio, puedes ignorar este correo: tu contrasena seguira igual.')
            ->salutation("Saludos,\nEquipo de {$brand}");
    }
}
