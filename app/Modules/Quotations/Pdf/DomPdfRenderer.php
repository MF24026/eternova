<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Pdf;

use App\Modules\Quotations\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Default PDF renderer backed by barryvdh/laravel-dompdf (pure PHP).
 *
 * No headless Chrome, no Node, no additional system packages — runs in the
 * existing Docker image as-is. CSS support is limited (no flexbox/grid);
 * the Blade template uses tables and inline styles exclusively.
 *
 * Responsibilities:
 *   1. Eager-load items (ordered by sort_order) + customer + tenant.
 *   2. Build the QuotationPdfViewModel (all formatting, branding, money).
 *   3. Render the Blade template to HTML.
 *   4. Feed the HTML to DomPDF and return the raw PDF bytes.
 *
 * The tenant is resolved from the Quotation's own relation, NOT from the
 * 'currentTenant' binding — this renderer is safe to call from queued jobs.
 */
final class DomPdfRenderer implements QuotationPdfRenderer
{
    private const TEMPLATE = 'pdf.quotation';

    public function render(Quotation $quotation): string
    {
        // Eager-load everything the view-model and template need.
        $quotation->loadMissing(['items', 'customer', 'tenant']);

        $tenant = $quotation->tenant;

        if ($tenant === null) {
            throw new PdfException(
                "Cannot render PDF for quotation #{$quotation->id}: tenant relation is null. "
                . 'Ensure the quotation has a valid tenant_id.',
            );
        }

        $viewModel = new QuotationPdfViewModel($quotation, $tenant);

        $html = View::make(self::TEMPLATE, ['vm' => $viewModel])->render();

        try {
            $pdf = Pdf::setOptions($this->dompdfOptions())
                ->loadHTML($html);

            $output = $pdf->output();
        } catch (RuntimeException $e) {
            throw new PdfException(
                "DomPDF failed to render quotation #{$quotation->quotation_number}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($output === null || $output === '') {
            throw new PdfException(
                "DomPDF returned empty output for quotation #{$quotation->quotation_number}.",
            );
        }

        return $output;
    }

    /**
     * Build the options array passed to DomPDF.
     *
     * Options documented in config/pdf.php (dompdf section).
     *
     * @return array<string, mixed>
     */
    private function dompdfOptions(): array
    {
        return [
            'defaultPaperSize'        => config('pdf.dompdf.paper_size', 'letter'),
            'defaultPaperOrientation' => config('pdf.dompdf.paper_orient', 'portrait'),
            'isRemoteEnabled'         => (bool) config('pdf.dompdf.enable_remote', false),
            'chroot'                  => config('pdf.dompdf.chroot', storage_path('app')),
            'isHtml5ParserEnabled'    => true,
            'isPhpEnabled'            => false,   // no PHP execution inside templates
            'dpi'                     => 150,
            'fontHeightRatio'         => 1.1,
        ];
    }
}
