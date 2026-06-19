<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Renders a billing invoice to a stored PDF and records its path on the invoice. Mirrors the
 * quotations PDF pipeline (DomPDF, pure PHP, table-based template) — no headless Chrome.
 *
 * These invoices are SaaS->tenant (the subscription bill), so they carry the Eternova brand,
 * not the tenant's storefront brand.
 */
final class InvoicePdfService
{
    private const TEMPLATE = 'pdf.invoice';

    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing(['tenant', 'subscription.plan']);

        $html = View::make(self::TEMPLATE, ['invoice' => $invoice])->render();

        try {
            $output = Pdf::setOptions(['isHtml5ParserEnabled' => true, 'isPhpEnabled' => false])
                ->loadHTML($html)
                ->output();
        } catch (RuntimeException $e) {
            throw new RuntimeException("Failed to render invoice {$invoice->number}: {$e->getMessage()}", previous: $e);
        }

        $path = "invoices/{$invoice->tenant_id}/{$invoice->number}.pdf";
        Storage::put($path, $output);

        $invoice->forceFill(['pdf_url' => $path])->save();

        return $path;
    }
}
