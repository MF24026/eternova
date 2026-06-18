<?php

declare(strict_types=1);

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Handlers\ChargebackHandler;
use App\Modules\Billing\Handlers\TransactionRefundedHandler;
use App\Modules\Billing\Handlers\TransactionUpdatedHandler;
use App\Modules\Billing\Models\WebhookLog;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes one received webhook asynchronously, so the receiver can answer Wompi in <2s.
 * Routes the event to its handler by type; an unknown type is acknowledged (completed) and
 * ignored rather than retried forever. On handler failure the row is marked failed and the
 * job rethrows so the queue retries with backoff.
 */
final class ProcessWompiWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $webhookLogId) {}

    public function handle(): void
    {
        $log = WebhookLog::findOrFail($this->webhookLogId);
        $log->markProcessing();

        try {
            /** @var array<string, mixed> $payload */
            $payload = $log->payload ?? [];
            $eventType = (string) ($payload['event'] ?? '');
            /** @var array<string, mixed> $data */
            $data = $payload['data'] ?? [];

            $handler = match ($eventType) {
                'transaction.updated' => app(TransactionUpdatedHandler::class),
                'transaction.refunded' => app(TransactionRefundedHandler::class),
                'chargeback.created' => app(ChargebackHandler::class),
                default => null,
            };

            if ($handler === null) {
                $log->update([
                    'status' => WebhookLog::STATUS_COMPLETED,
                    'processed_at' => now(),
                    'error' => 'unknown_event_type, ignored',
                ]);

                return;
            }

            $handler->handle($data);
            $log->markProcessed();
        } catch (Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }
}
