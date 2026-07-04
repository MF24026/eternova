<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Exceptions\IdempotencyInProgressException;
use App\Modules\Billing\Models\IdempotentOperation;
use App\Modules\Billing\Support\IdempotencyService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $service;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IdempotencyService;
        $this->tenantId = Tenant::factory()->create()->id;
    }

    public function test_same_key_runs_the_operation_once_and_caches_the_result(): void
    {
        $calls = 0;
        // Regular closure with by-reference capture: an arrow fn would capture $calls by
        // value, so the outer counter would never move and the assertion would be a lie.
        $op = function () use (&$calls): string {
            return 'tx_'.(++$calls);
        };

        $first = $this->service->execute('key-1', 'charge', $this->tenantId, $op);
        $second = $this->service->execute('key-1', 'charge', $this->tenantId, $op);

        $this->assertSame('tx_1', $first);
        $this->assertSame('tx_1', $second, 'Second call must return the cached result.');
        $this->assertSame(1, $calls, 'The wrapped operation must run only once.');
        $this->assertDatabaseCount('idempotent_operations', 1);
    }

    public function test_in_progress_operation_is_rejected(): void
    {
        // Pre-seed a pending row to simulate a concurrent in-flight attempt.
        IdempotentOperation::create([
            'key' => 'key-2',
            'operation' => 'charge',
            'tenant_id' => $this->tenantId,
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);

        $this->expectException(IdempotencyInProgressException::class);

        $this->service->execute('key-2', 'charge', $this->tenantId, fn () => 'should-not-run');
    }

    public function test_failed_operation_can_be_retried(): void
    {
        try {
            $this->service->execute('key-3', 'charge', $this->tenantId, function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('failed', IdempotentOperation::where('key', 'key-3')->firstOrFail()->status);

        $result = $this->service->execute('key-3', 'charge', $this->tenantId, fn () => 'recovered');

        $this->assertSame('recovered', $result);
        $this->assertDatabaseCount('idempotent_operations', 1);
    }

    public function test_same_key_is_isolated_per_tenant(): void
    {
        $otherTenant = Tenant::factory()->create()->id;

        $this->service->execute('key-4', 'charge', $this->tenantId, fn () => 'a');
        $this->service->execute('key-4', 'charge', $otherTenant, fn () => 'b');

        $this->assertDatabaseCount('idempotent_operations', 2);
    }

    public function test_same_key_different_operation_does_not_collide(): void
    {
        $this->service->execute('key-5', 'charge', $this->tenantId, fn () => 'charged');
        $refund = $this->service->execute('key-5', 'refund', $this->tenantId, fn () => 'refunded');

        $this->assertSame('refunded', $refund);
        $this->assertDatabaseCount('idempotent_operations', 2);
    }
}
