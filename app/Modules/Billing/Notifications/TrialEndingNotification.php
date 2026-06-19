<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** SaaS -> tenant owner: the free trial is about to end; add a payment method to keep access. */
final class TrialEndingNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly int $daysLeft) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu periodo de prueba esta por terminar')
            ->greeting('Hola')
            ->line("Tu prueba termina en {$this->daysLeft} dia(s).")
            ->line('Agrega un metodo de pago para no perder el acceso a tu cuenta ni tus datos.')
            ->action('Agregar metodo de pago', url('/account/billing'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'billing.trial_ending', 'days_left' => $this->daysLeft];
    }

    public function databaseType(object $notifiable): string
    {
        return 'billing.trial_ending';
    }
}
