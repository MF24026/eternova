<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\Invoice;
use App\Support\Money\Format;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/** SaaS -> tenant owner: the renewal succeeded; the invoice PDF is attached. */
final class InvoiceReadyNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->invoice->tenant ? Format::localeForTenant($this->invoice->tenant) : 'es-SV';
        $amount = Format::number($this->invoice->total_cents, $this->invoice->currency ?: 'USD', $locale);

        $mail = (new MailMessage)
            ->subject("Tu factura {$this->invoice->number}")
            ->greeting('Hola')
            ->line('Gracias por tu pago. Adjuntamos la factura de tu suscripcion.')
            ->line("**Total:** {$this->invoice->currency} {$amount}")
            ->action('Ver mis facturas', url('/account/billing/invoices'));

        if ($this->invoice->pdf_url !== null && Storage::exists($this->invoice->pdf_url)) {
            $mail->attachData(
                Storage::get($this->invoice->pdf_url),
                "{$this->invoice->number}.pdf",
                ['mime' => 'application/pdf'],
            );
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'billing.invoice_ready',
            'invoice_id' => $this->invoice->id,
            'number' => $this->invoice->number,
            'total_cents' => $this->invoice->total_cents,
            'currency' => $this->invoice->currency,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'billing.invoice_ready';
    }
}
