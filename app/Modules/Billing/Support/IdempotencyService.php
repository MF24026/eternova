<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Exceptions\IdempotencyInProgressException;
use App\Modules\Billing\Models\IdempotentOperation;
use Throwable;

/**
 * Wraps a money operation so it runs at most once per (key, operation, tenant) even if the
 * caller retries. The first call creates a 'pending' row and runs the closure; a retry with
 * the same key returns the cached result if the first completed, or refuses (throws) if it
 * is still in flight.
 *
 * The DB-level UNIQUE on the row is the real guarantee; this service is the fast path on top.
 */
final class IdempotencyService
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     *
     * @throws IdempotencyInProgressException when a same-key operation is still pending
     */
    public function execute(string $key, string $operation, string $tenantId, callable $fn): mixed
    {
        $record = IdempotentOperation::firstOrCreate(
            ['key' => $key, 'operation' => $operation, 'tenant_id' => $tenantId],
            ['status' => 'pending', 'expires_at' => now()->addHours(24)],
        );

        if (! $record->wasRecentlyCreated) {
            if ($record->status === 'completed') {
                return unserialize($record->result);
            }

            if ($record->status === 'pending') {
                throw new IdempotencyInProgressException(
                    "Operation [{$operation}:{$key}] is already in progress."
                );
            }
            // status === 'failed' — allow a fresh attempt by reusing this row.
        }

        try {
            $result = $fn();
            $record->update(['status' => 'completed', 'result' => serialize($result), 'error_message' => null]);

            return $result;
        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }
}
