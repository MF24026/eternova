<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SaaS -> tenant owner: dunning exhausted, account suspended; pay to restore access. */
final class SubscriptionSuspendedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Subscription $subscription) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu cuenta fue suspendida')
            ->greeting('Hola')
            ->line('Tras varios intentos no pudimos cobrar tu suscripcion, por lo que tu cuenta quedo suspendida (solo lectura).')
            ->line('Realiza el pago pendiente para restaurar el acceso completo.')
            ->action('Reactivar mi cuenta', url('/account/billing'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'billing.suspended', 'subscription_id' => $this->subscription->id];
    }

    public function databaseType(object $notifiable): string
    {
        return 'billing.suspended';
    }
}
