<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SaaS -> tenant owner: a charge failed; update the card before retries run out. */
final class ChargeFailedNotification extends Notification
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
            ->subject('No pudimos procesar tu pago')
            ->greeting('Hola')
            ->line('El ultimo cobro de tu suscripcion fue rechazado.')
            ->line('Reintentaremos en los proximos dias. Actualiza tu tarjeta para evitar la suspension de tu cuenta.')
            ->action('Actualizar metodo de pago', url('/account/billing/payment-method'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'billing.charge_failed',
            'subscription_id' => $this->subscription->id,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'billing.charge_failed';
    }
}
