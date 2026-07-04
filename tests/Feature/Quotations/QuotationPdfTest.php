<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Pdf\DomPdfRenderer;
use App\Modules\Quotations\Pdf\QuotationPdfRenderer;
use App\Modules\Quotations\Pdf\QuotationPdfViewModel;
use App\Modules\Quotations\Services\QuotationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the Quotation PDF pipeline (S7-E4).
 *
 * Coverage strategy:
 *   - DomPdfRenderer produces non-empty bytes starting with %PDF magic header.
 *   - Rendered HTML (via QuotationPdfViewModel + Blade view) contains expected
 *     content: quotation number, tenant name, customer name, item descriptions,
 *     formatted totals, and status label. Asserting HTML is faster and more
 *     readable than asserting on binary PDF content.
 *   - QuotationPdfViewModel formats cents correctly in the tenant currency.
 *   - Branding: custom business_name appears; the string "Eternova" is never
 *     hardcoded in the rendered output regardless of tenant name.
 *   - Endpoint GET /{id}/pdf → 200 application/pdf for authorized owner.
 *   - Endpoint → 401 unauthenticated; 403/404 cross-tenant.
 *
 * All tests use real DomPDF (pure PHP) — no mocking.
 * RefreshDatabase resets state between tests.
 */
final class QuotationPdfTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private QuotationService $quotationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotationService = app(QuotationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(string $businessName = 'Rosas Eternas SV'): array
    {
        $tenant = Tenant::factory()->create([
            'business_name' => $businessName,
            'primary_color' => '#7c545d',
            'secondary_color' => '#5a4b71',
            // Pin country + currency together: the factory otherwise randomizes the
            // country (SV/CO), and the locale drives the number's decimal glyph
            // (es-SV "185.00" vs es-CO "185,00"). USD billing implies SV here.
            'country_code' => 'SV',
            'currency' => 'USD',
            'language' => 'es',
            'quotation_tax_rate_bps' => 1300,
            'quotation_valid_days' => 15,
        ]);

        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createQuotation(Tenant $tenant, User $actor, array $overrides = []): Quotation
    {
        app()->instance('currentTenant', $tenant);

        return $this->quotationService->create(
            data: array_merge([
                'issue_date' => '2026-06-12',
                'valid_until' => '2026-06-27',
                'discount_cents' => 0,
                'tax_rate_bps' => 0,
                'items' => [
                    ['description' => 'Arreglo floral especial', 'quantity' => 2, 'unit_price_cents' => 7500],
                    ['description' => 'Caja de chocolates', 'quantity' => 1, 'unit_price_cents' => 3500],
                ],
            ], $overrides),
            actor: $actor,
        );
    }

    // ── Renderer: bytes ───────────────────────────────────────────────────────

    public function test_renderer_returns_non_empty_bytes_starting_with_pdf_magic_header(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $renderer = app(QuotationPdfRenderer::class);
        $bytes = $renderer->render($quotation);

        $this->assertNotEmpty($bytes, 'DomPDF output must not be empty.');
        $this->assertStringStartsWith('%PDF', $bytes, 'PDF binary must start with the %PDF magic header.');
    }

    public function test_renderer_resolves_to_dompdf_driver_by_default(): void
    {
        $renderer = app(QuotationPdfRenderer::class);

        $this->assertInstanceOf(DomPdfRenderer::class, $renderer);
    }

    // ── View-model: formatting ────────────────────────────────────────────────

    public function test_viewmodel_formats_cents_as_currency_string(): void
    {
        $this->assertSame('USD 15.00', QuotationPdfViewModel::formatCents(1500, 'USD'));
        $this->assertSame('USD 1,500.00', QuotationPdfViewModel::formatCents(150000, 'USD'));
        // COP is a zero-decimal currency: 1 minor unit is 1 peso, not "0.01".
        $this->assertSame('COP 1', QuotationPdfViewModel::formatCents(1, 'COP'));
        $this->assertSame('COP 150.000', QuotationPdfViewModel::formatCents(150000, 'COP', 'es-CO'));
        $this->assertSame('USD 0.00', QuotationPdfViewModel::formatCents(0, 'USD'));
    }

    public function test_viewmodel_builds_correct_totals_from_quotation(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        // subtotal = 2*7500 + 1*3500 = 18500
        // discount = 500
        // taxable  = 18500 - 500 = 18000
        // tax      = intdiv(18000 * 1300, 10000) = 2340
        // total    = 18000 + 2340 = 20340
        $quotation = $this->createQuotation($tenant, $owner, [
            'discount_cents' => 500,
            'tax_rate_bps' => 1300,
            'items' => [
                ['description' => 'Item A', 'quantity' => 2, 'unit_price_cents' => 7500],
                ['description' => 'Item B', 'quantity' => 1, 'unit_price_cents' => 3500],
            ],
        ]);

        $quotation->loadMissing(['items', 'customer', 'tenant']);
        $vm = new QuotationPdfViewModel($quotation, $quotation->tenant);

        $this->assertSame('USD 185.00', $vm->subtotal);
        $this->assertSame('USD 5.00', $vm->discount);
        $this->assertSame('IVA (13%)', $vm->taxLabel);
        $this->assertSame('USD 23.40', $vm->tax);
        $this->assertSame('USD 203.40', $vm->total);
    }

    public function test_viewmodel_omits_discount_and_tax_rows_when_zero(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner, [
            'discount_cents' => 0,
            'tax_rate_bps' => 0,
            'items' => [
                ['description' => 'Rosa única', 'quantity' => 1, 'unit_price_cents' => 5000],
            ],
        ]);

        $quotation->loadMissing(['items', 'customer', 'tenant']);
        $vm = new QuotationPdfViewModel($quotation, $quotation->tenant);

        $this->assertNull($vm->discount, 'discount must be null when discount_cents is 0.');
        $this->assertNull($vm->taxLabel, 'taxLabel must be null when tax_rate_bps is 0.');
        $this->assertNull($vm->tax, 'tax must be null when tax_rate_bps is 0.');
    }

    // ── Rendered HTML content ─────────────────────────────────────────────────

    /**
     * Render the Blade template to HTML (not PDF binary) to assert content.
     *
     * This is faster and more readable than inspecting the PDF binary; we
     * separately assert the binary starts with %PDF in the bytes test above.
     */
    public function test_rendered_html_contains_quotation_number(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString($quotation->quotation_number, $html);
    }

    public function test_rendered_html_contains_tenant_business_name(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant('Boutique Los Lirios');
        $quotation = $this->createQuotation($tenant, $owner);

        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString('Boutique Los Lirios', $html);
    }

    public function test_rendered_html_never_contains_hardcoded_eternova(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant('Flores del Valle');
        $quotation = $this->createQuotation($tenant, $owner);

        $html = $this->renderToHtml($quotation);

        // The word "Eternova" must not appear in the PDF — only tenant branding.
        // (The SaaS name must never leak into tenant documents.)
        $this->assertStringNotContainsStringIgnoringCase(
            'Eternova',
            $html,
            'The PDF template must not hardcode the SaaS brand name "Eternova". '
            .'All branding must come from the Tenant model.',
        );
    }

    public function test_rendered_html_contains_customer_name_when_linked(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $customer = Customer::factory()->forTenant($tenant)->create([
            'name' => 'Maria Gonzalez',
            'email' => 'maria@example.com',
        ]);

        $quotation = $this->createQuotation($tenant, $owner, ['customer_id' => $customer->id]);

        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString('Maria Gonzalez', $html);
        $this->assertStringContainsString('maria@example.com', $html);
    }

    public function test_rendered_html_contains_all_line_item_descriptions(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner, [
            'items' => [
                ['description' => 'Corona fúnebre grande', 'quantity' => 1, 'unit_price_cents' => 15000],
                ['description' => 'Ramo de rosas rojas x24', 'quantity' => 2, 'unit_price_cents' => 8000],
                ['description' => 'Decoración de mesa nupcial', 'quantity' => 5, 'unit_price_cents' => 4500],
            ],
        ]);

        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString('Corona fúnebre grande', $html);
        $this->assertStringContainsString('Ramo de rosas rojas x24', $html);
        $this->assertStringContainsString('Decoración de mesa nupcial', $html);
    }

    public function test_rendered_html_contains_formatted_total(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        // total = 1 * 10000 = 10000 cents = USD 100.00
        $quotation = $this->createQuotation($tenant, $owner, [
            'discount_cents' => 0,
            'tax_rate_bps' => 0,
            'items' => [
                ['description' => 'Servicio único', 'quantity' => 1, 'unit_price_cents' => 10000],
            ],
        ]);

        $html = $this->renderToHtml($quotation);

        // Both the item price and the total should appear as "USD 100.00"
        $this->assertStringContainsString('USD 100.00', $html);
    }

    public function test_rendered_html_shows_iva_row_when_tax_rate_set(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner, [
            'tax_rate_bps' => 1300,
            'items' => [
                ['description' => 'Producto', 'quantity' => 1, 'unit_price_cents' => 10000],
            ],
        ]);

        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString('IVA (13%)', $html);
    }

    public function test_rendered_html_shows_status_label_in_spanish(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $html = $this->renderToHtml($quotation);

        // Newly created quotations are always 'draft' → "Borrador"
        $this->assertStringContainsString('Borrador', $html);
    }

    public function test_rendered_html_shows_validity_date_when_set(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner, ['valid_until' => '2026-06-30']);

        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString('30/06/2026', $html);
    }

    public function test_rendered_html_shows_no_customer_text_when_no_customer_linked(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString('Sin cliente asignado', $html);
    }

    // ── Endpoint: authorization gates ─────────────────────────────────────────

    public function test_unauthenticated_pdf_request_returns_401(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $this->getJson($this->tenantUrl($tenant, "/api/v1/quotations/{$quotation->id}/pdf"))
            ->assertStatus(401);
    }

    public function test_owner_can_download_pdf_and_gets_application_pdf_content_type(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/pdf");

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type') ?? '');
    }

    public function test_pdf_response_has_inline_content_disposition_with_pdf_extension(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/pdf");

        $response->assertStatus(200);

        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
    }

    public function test_pdf_binary_response_starts_with_pdf_magic_header(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $quotation = $this->createQuotation($tenant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/pdf");

        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringStartsWith('%PDF', $body, 'HTTP response body must start with %PDF.');
    }

    public function test_customer_role_cannot_download_pdf(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $customer = User::factory()->forTenant($tenant, role: 'customer')->create();
        $quotation = $this->createQuotation($tenant, $owner);

        $this->tenantGetJson($tenant, $customer, "/api/v1/quotations/{$quotation->id}/pdf")
            ->assertStatus(403);
    }

    public function test_cross_tenant_user_cannot_download_pdf(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant('Boutique B');

        $quotationA = $this->createQuotation($tenantA, $ownerA);

        $response = $this->tenantGetJson($tenantB, $ownerB, "/api/v1/quotations/{$quotationA->id}/pdf");
        $this->assertContains($response->status(), [403, 404]);
    }

    // ── Branding: tenant isolation ────────────────────────────────────────────

    public function test_pdf_content_uses_tenant_business_name_not_saas_brand(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant('Floreria La Primavera');
        $quotation = $this->createQuotation($tenant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/pdf");
        $response->assertStatus(200);

        // We cannot easily parse the PDF binary, so we render to HTML separately
        // and assert the content — the PDF bytes test above already validates %PDF.
        $html = $this->renderToHtml($quotation);

        $this->assertStringContainsString('Floreria La Primavera', $html);
        $this->assertStringNotContainsStringIgnoringCase('Eternova', $html);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Render the quotation Blade template to HTML for content assertions.
     *
     * Rendering HTML is faster than full PDF generation and produces a
     * human-readable string we can assertStringContainsString() against.
     * DomPDF correctness (binary %PDF header) is verified in the bytes tests.
     */
    private function renderToHtml(Quotation $quotation): string
    {
        $quotation->loadMissing(['items', 'customer', 'tenant']);

        $tenant = $quotation->tenant;
        $this->assertNotNull($tenant, 'Quotation tenant relation must not be null in test.');

        $vm = new QuotationPdfViewModel($quotation, $tenant);

        return view('pdf.quotation', ['vm' => $vm])->render();
    }
}
