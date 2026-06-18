<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Exceptions\CircuitOpenException;
use App\Modules\Billing\Support\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker('test:'.uniqid(), threshold: 3, cooldownSeconds: 60);
    }

    public function test_closed_circuit_executes_and_returns_the_value(): void
    {
        $this->assertSame('ok', $this->breaker()->execute(fn () => 'ok'));
    }

    public function test_circuit_opens_after_threshold_consecutive_failures(): void
    {
        $breaker = $this->breaker();

        // Three failures hit the threshold; the inner exception is rethrown each time.
        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->execute(fn () => throw new RuntimeException('downstream down'));
            } catch (RuntimeException) {
                // expected — counts as a failure
            }
        }

        $this->assertTrue($breaker->isOpen());

        // Now the circuit fails fast without invoking the callable.
        $this->expectException(CircuitOpenException::class);
        $breaker->execute(fn () => 'should-not-run');
    }

    public function test_success_resets_the_failure_count(): void
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->execute(fn () => throw new RuntimeException('blip'));
            } catch (RuntimeException) {
            }
        }

        // A success before the threshold clears the streak.
        $breaker->execute(fn () => 'ok');

        // Two more failures should NOT open it (count was reset).
        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->execute(fn () => throw new RuntimeException('blip'));
            } catch (RuntimeException) {
            }
        }

        $this->assertFalse($breaker->isOpen());
    }

    public function test_circuit_recovers_after_cooldown(): void
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->execute(fn () => throw new RuntimeException('down'));
            } catch (RuntimeException) {
            }
        }

        $this->assertTrue($breaker->isOpen());

        $this->travel(61)->seconds();

        $this->assertFalse($breaker->isOpen());
        $this->assertSame('recovered', $breaker->execute(fn () => 'recovered'));
    }
}
