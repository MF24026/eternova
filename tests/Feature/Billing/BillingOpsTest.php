<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 8 ops: the billing:maintenance CLI and the dedicated billing log channel.
 *
 * (The cross-cutting security invariants — webhook HMAC bypass/replay, idempotency, PCI token
 * non-leak, cross-tenant isolation — are asserted in their own phase suites: WompiWebhook
 * ReceiverTest, IdempotencyServiceTest, BillingPciSmokeTest, BillingAccountApiTest.)
 */
final class BillingOpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_maintenance_enable_and_disable(): void
    {
        $this->assertFalse(BillingMaintenance::isEnabled());

        $this->artisan('billing:maintenance', ['action' => 'enable'])->assertSuccessful();
        $this->assertTrue(BillingMaintenance::isEnabled());

        $this->artisan('billing:maintenance', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse(BillingMaintenance::isEnabled());
    }

    public function test_maintenance_status_json(): void
    {
        BillingMaintenance::enable();

        $this->artisan('billing:maintenance', ['action' => 'status', '--format' => 'json'])
            ->expectsOutputToContain('"maintenance":true')
            ->assertSuccessful();
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->artisan('billing:maintenance', ['action' => 'frobnicate'])
            ->assertExitCode(2); // Command::INVALID
    }

    public function test_billing_log_channel_is_configured_for_long_retention(): void
    {
        $channel = config('logging.channels.billing');

        $this->assertIsArray($channel);
        $this->assertSame('daily', $channel['driver']);
        $this->assertSame(1825, $channel['days']);
        $this->assertSame(0600, $channel['permission']);
        $this->assertStringContainsString('billing/billing.log', $channel['path']);
    }
}
