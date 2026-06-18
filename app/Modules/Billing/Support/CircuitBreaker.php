<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A minimal circuit breaker around a flaky downstream (the payment gateway). After
 * $threshold consecutive failures it "opens" for $cooldownSeconds, during which calls fail
 * fast with CircuitOpenException instead of hammering a provider that is clearly down.
 *
 * State lives in the cache (Redis in prod, array in tests) so it is shared across workers.
 *
 * CRITICAL: use a SEPARATE breaker key per call type — a broken auth endpoint must not stop
 * charges from being attempted, since they are independent services on the provider side.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly string $key,
        private readonly int $threshold = 5,
        private readonly int $cooldownSeconds = 60,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     *
     * @throws CircuitOpenException when the circuit is open
     */
    public function execute(callable $fn): mixed
    {
        if ($this->isOpen()) {
            throw new CircuitOpenException("Circuit [{$this->key}] is open");
        }

        try {
            $result = $fn();
            $this->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    public function isOpen(): bool
    {
        $openUntil = Cache::get($this->openUntilKey());

        return $openUntil !== null && (int) $openUntil > now()->getTimestamp();
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failuresKey());
        Cache::forget($this->openUntilKey());
    }

    public function recordFailure(): void
    {
        $failures = (int) Cache::get($this->failuresKey(), 0) + 1;
        Cache::put($this->failuresKey(), $failures, now()->addSeconds($this->cooldownSeconds * 2));

        if ($failures >= $this->threshold) {
            Cache::put(
                $this->openUntilKey(),
                now()->addSeconds($this->cooldownSeconds)->getTimestamp(),
                now()->addSeconds($this->cooldownSeconds),
            );
        }
    }

    private function failuresKey(): string
    {
        return "circuit:{$this->key}:failures";
    }

    private function openUntilKey(): string
    {
        return "circuit:{$this->key}:open_until";
    }
}
