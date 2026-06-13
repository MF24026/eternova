<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Pdf;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * View-model passed to the quotation Blade PDF template.
 *
 * All money values are pre-formatted strings (e.g. "USD 1,500.00") so the
 * template itself never does arithmetic or number formatting. This keeps the
 * template dumb and makes the formatting testable independently.
 *
 * All properties are readonly; the object is created once in DomPdfRenderer
 * and passed directly to the Blade view.
 */
final readonly class QuotationPdfViewModel
{
    // ── Document metadata ─────────────────────────────────────────────────────

    public string $quotationNumber;
    public string $status;
    public string $statusLabel;
    public string $issueDate;
    public ?string $validUntil;

    // ── Tenant branding ───────────────────────────────────────────────────────

    public string $tenantName;
    public ?string $tenantLogoBase64;   // data:image/...;base64,... or null
    public ?string $tenantLogoMime;
    public string $primaryColor;
    public string $secondaryColor;
    public ?string $tenantAddress;
    public ?string $tenantPhone;
    public ?string $tenantTagline;
    public string $currency;

    // ── Customer ──────────────────────────────────────────────────────────────

    public ?string $customerName;
    public ?string $customerEmail;
    public ?string $customerPhone;

    // ── Line items ────────────────────────────────────────────────────────────

    /** @var Collection<int, array{description: string, quantity: int, unit_price: string, line_total: string}> */
    public Collection $items;

    // ── Totals (pre-formatted) ────────────────────────────────────────────────

    public string $subtotal;
    public ?string $discount;       // null when discount_cents === 0
    public ?string $taxLabel;       // e.g. "IVA (13%)" — null when no tax
    public ?string $tax;            // formatted tax amount — null when no tax
    public string $total;

    // ── Notes & terms ─────────────────────────────────────────────────────────

    public ?string $notes;
    public ?string $terms;

    public function __construct(Quotation $quotation, Tenant $tenant)
    {
        // ── Document metadata ─────────────────────────────────────────────
        $this->quotationNumber = $quotation->quotation_number;
        $this->status          = $quotation->status;
        $this->statusLabel     = $this->resolveStatusLabel($quotation->status);
        $this->issueDate       = $quotation->issue_date->format('d/m/Y');
        $this->validUntil      = $quotation->valid_until?->format('d/m/Y');

        // ── Tenant branding ───────────────────────────────────────────────
        $this->tenantName      = $tenant->business_name ?? $tenant->name;
        $this->primaryColor    = $tenant->primary_color ?? '#7c545d';
        $this->secondaryColor  = $tenant->secondary_color ?? '#5a4b71';
        $this->currency        = $tenant->currency ?? 'USD';
        $this->tenantTagline   = $tenant->brand_extra['tagline'] ?? null;
        $this->tenantAddress   = $tenant->brand_extra['address'] ?? null;
        $this->tenantPhone     = $tenant->brand_extra['phone'] ?? $tenant->brand_extra['phones'][0] ?? null;

        [$this->tenantLogoBase64, $this->tenantLogoMime] = $this->resolveLogoBase64($tenant);

        // ── Customer ──────────────────────────────────────────────────────
        $customer                = $quotation->customer;
        $this->customerName      = $customer?->name;
        $this->customerEmail     = $customer?->email;
        $this->customerPhone     = $customer?->phone;

        // ── Line items ────────────────────────────────────────────────────
        $currency    = $this->currency;
        $this->items = $quotation->items->map(
            fn ($item) => [
                'description' => $item->description,
                'quantity'    => $item->quantity,
                'unit_price'  => self::formatCents($item->unit_price_cents, $currency),
                'line_total'  => self::formatCents($item->line_total_cents, $currency),
            ]
        );

        // ── Totals ────────────────────────────────────────────────────────
        $this->subtotal = self::formatCents($quotation->subtotal_cents, $currency);

        $this->discount = $quotation->discount_cents > 0
            ? self::formatCents($quotation->discount_cents, $currency)
            : null;

        if ($quotation->tax_rate_bps > 0) {
            $ratePct        = $quotation->tax_rate_bps / 100;
            $this->taxLabel = sprintf('IVA (%.0f%%)', $ratePct);
            $this->tax      = self::formatCents($quotation->tax_cents, $currency);
        } else {
            $this->taxLabel = null;
            $this->tax      = null;
        }

        $this->total = self::formatCents($quotation->total_cents, $currency);

        // ── Notes & terms ─────────────────────────────────────────────────
        $this->notes = $quotation->notes;
        $this->terms = $quotation->terms;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Format an integer centavos value as a human-readable currency string.
     *
     * Output example: "USD 1,500.00" — currency code prefix is intentional
     * for LatAm documents where the symbol alone is ambiguous ($ = USD/SV/CO/MX).
     */
    public static function formatCents(int $cents, string $currency): string
    {
        $amount = $cents / 100;

        return sprintf('%s %s', $currency, number_format($amount, 2, '.', ','));
    }

    /**
     * Resolve the status to a Spanish user-facing label for the PDF badge.
     */
    private function resolveStatusLabel(string $status): string
    {
        return match ($status) {
            'draft'    => 'Borrador',
            'sent'     => 'Enviada',
            'accepted' => 'Aceptada',
            'rejected' => 'Rechazada',
            'expired'  => 'Vencida',
            default    => ucfirst($status),
        };
    }

    /**
     * Embed the tenant logo as a base64 data URI for DomPDF.
     *
     * Strategy — local-first to avoid slow/insecure HTTP fetches:
     *   1. If logo_url is a path under storage/app/public, resolve it to an
     *      absolute disk path and base64-encode the file contents.
     *   2. If logo_url is a full external URL (https://...) AND
     *      PDF_ENABLE_REMOTE is true, we return null and let the template
     *      use the raw URL (DomPDF will fetch it via cURL). This is gated
     *      because it adds latency and exposes the server IP to the image host.
     *   3. No logo → return [null, null] → template shows the tenant name.
     *
     * Trade-off documented in config/pdf.php (enable_remote key).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveLogoBase64(Tenant $tenant): array
    {
        $logoUrl = $tenant->logo_url;

        if (! $logoUrl) {
            return [null, null];
        }

        // Attempt local disk resolution — strip leading slash or 'storage/' prefix.
        $relativePath = ltrim($logoUrl, '/');

        // Handle URLs like /storage/tenants/.../logo.jpg
        if (str_starts_with($relativePath, 'storage/')) {
            $relativePath = 'public/' . substr($relativePath, strlen('storage/'));
        }

        $absolutePath = storage_path('app/' . $relativePath);

        if (file_exists($absolutePath)) {
            $bytes    = file_get_contents($absolutePath);
            $mime     = mime_content_type($absolutePath) ?: 'image/jpeg';
            $encoded  = base64_encode($bytes !== false ? $bytes : '');

            return ["data:{$mime};base64,{$encoded}", $mime];
        }

        // External URL — only usable when PDF_ENABLE_REMOTE=true.
        // Return null here; DomPdfRenderer enables the remote option and
        // the template will use the raw URL if base64 is null.
        return [null, null];
    }
}
