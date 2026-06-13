<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Pdf;

use App\Modules\Quotations\Models\Quotation;

/**
 * Contract for PDF rendering backends.
 *
 * Implementations are responsible for:
 *   1. Eager-loading all relations the template needs (items, customer, tenant).
 *   2. Applying tenant branding (logo, colors, currency, contact details).
 *   3. Returning the raw PDF binary so the caller can stream it directly.
 *
 * The interface is intentionally minimal — a single method — so that
 * alternative backends (Browsershot, Gotenberg) can be plugged in later
 * without touching controller or service code. Driver selection is owned
 * by config/pdf.php, mirroring the OcrDriverInterface pattern from S6.
 */
interface QuotationPdfRenderer
{
    /**
     * Render the quotation as a PDF document.
     *
     * @param  Quotation $quotation  The quotation to render. The renderer is
     *                               responsible for loading any missing relations;
     *                               do not assume they are already loaded.
     *
     * @return string  Raw PDF bytes (starts with the %PDF magic header).
     */
    public function render(Quotation $quotation): string;
}
