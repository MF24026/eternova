<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The invoice PDF must format its total in the invoice's own currency snapshot,
 * respecting that currency's decimals and the tenant's regional grouping — not a
 * hardcoded "cents / 100, 2 decimals" that breaks zero-decimal currencies.
 */
final class InvoicePdfCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function renderFor(Tenant $tenant, string $currency, int $totalCents): string
    {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => $currency,
            'subtotal_cents' => $totalCents,
            'tax_cents' => 0,
            'total_cents' => $totalCents,
        ]);

        $invoice->loadMissing(['tenant', 'subscription.plan']);

        return View::make('pdf.invoice', ['invoice' => $invoice])->render();
    }

    public function test_zero_decimal_currency_renders_without_cents(): void
    {
        $tenant = Tenant::factory()->create([
            'country_code' => 'CO',
            'currency' => 'COP',
            'language' => 'es',
        ]);

        // 150000 pesos (COP is zero-decimal) -> "150.000" in es-CO, never "150,000.00".
        $html = $this->renderFor($tenant, 'COP', 150000);

        $this->assertStringContainsString('150.000', $html);
        $this->assertStringNotContainsString('150,000.00', $html);
    }

    public function test_two_decimal_currency_keeps_cents(): void
    {
        $tenant = Tenant::factory()->create([
            'country_code' => 'SV',
            'currency' => 'USD',
            'language' => 'es',
        ]);

        // 123456 cents -> "1,234.56" in es-SV.
        $html = $this->renderFor($tenant, 'USD', 123456);

        $this->assertStringContainsString('1,234.56', $html);
    }
}
