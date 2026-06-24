<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoicePdfService;
use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice PDF must carry the tenant's fiscal document (DUI/NIT) so a card or
 * tax audit finds it on every invoice. The value is resolved from the tenant's
 * tax default settings, even though the PDF is generated in a queued job without
 * a bound tenant context.
 */
final class InvoiceFiscalIdTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Tenant $tenant): Invoice
    {
        return Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'USD',
        ]);
    }

    private function writeTaxDefault(Tenant $tenant, string $label, string $number): void
    {
        foreach (['id_label' => $label, 'id_number' => $number] as $key => $value) {
            BranchSetting::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => null,
                'group' => 'tax',
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    public function test_invoice_pdf_shows_the_fiscal_id_when_set(): void
    {
        $tenant = Tenant::factory()->create(['country_code' => 'SV', 'business_name' => 'Rosa Eterna']);
        $this->writeTaxDefault($tenant, 'DUI', '04210323-4');

        $html = (new InvoicePdfService())->renderHtml($this->invoiceFor($tenant));

        $this->assertStringContainsString('DUI: 04210323-4', $html);
    }

    public function test_invoice_pdf_omits_fiscal_line_when_not_set(): void
    {
        $tenant = Tenant::factory()->create(['country_code' => 'SV', 'business_name' => 'Sin Fiscal']);

        $html = (new InvoicePdfService())->renderHtml($this->invoiceFor($tenant));

        // No tax rows -> no fiscal line, and the document still renders the tenant.
        $this->assertStringContainsString('Sin Fiscal', $html);
        $this->assertStringNotContainsString('04210323-4', $html);
    }
}
