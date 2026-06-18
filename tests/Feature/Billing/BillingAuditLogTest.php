<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The audit log is append-only by enforcement, not just convention.
 */
final class BillingAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function entry(): BillingAuditLog
    {
        $tenant = Tenant::factory()->create();

        return BillingAuditLog::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => null,
            'event_type' => 'subscription.state_changed',
            'payload' => ['from' => 'active', 'to' => 'past_due'],
            'correlation_id' => 'corr-1',
            'occurred_at' => now(),
        ]);
    }

    public function test_entry_can_be_created_and_payload_is_cast_to_array(): void
    {
        $entry = $this->entry();

        $this->assertDatabaseCount('billing_audit_log', 1);
        $this->assertIsArray($entry->payload);
        $this->assertSame('past_due', $entry->payload['to']);
    }

    public function test_entry_cannot_be_updated(): void
    {
        $entry = $this->entry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/i');

        $entry->update(['event_type' => 'tampered']);
    }

    public function test_entry_cannot_be_deleted(): void
    {
        $entry = $this->entry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/i');

        $entry->delete();
    }
}
