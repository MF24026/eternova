<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Settings\Models\BranchSetting;
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
        $html = $this->renderHtml($invoice);

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

    /**
     * Render the invoice to its HTML (pre-PDF). Exposed as a seam so the
     * fiscal-id + currency rendering can be asserted without parsing binary PDF.
     */
    public function renderHtml(Invoice $invoice): string
    {
        $invoice->loadMissing(['tenant', 'subscription.plan']);

        return View::make(self::TEMPLATE, [
            'invoice' => $invoice,
            'taxIdentity' => $this->taxIdentity($invoice),
        ])->render();
    }

    /**
     * The tenant's fiscal identity (label + number) for the invoice header, so a
     * card/tax audit finds the document on every invoice. Resolved directly from
     * the tenant's tax default rows (this runs in a queued job with no bound tenant
     * context, so the global TenantScope can't be relied on). Returns null when the
     * tenant has not filled a fiscal number.
     *
     * @return array{label: string, number: string}|null
     */
    private function taxIdentity(Invoice $invoice): ?array
    {
        $rows = BranchSetting::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('group', 'tax')
            ->whereNull('branch_id')
            ->pluck('value', 'key');

        $number = $rows['id_number'] ?? null;

        if (! is_string($number) || $number === '') {
            return null;
        }

        $label = $rows['id_label'] ?? null;

        return [
            'label' => is_string($label) && $label !== '' ? $label : 'NIT',
            'number' => $number,
        ];
    }
}
