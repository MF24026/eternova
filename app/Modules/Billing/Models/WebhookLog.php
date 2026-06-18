<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Database\Factories\WebhookLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class WebhookLog extends Model
{
    /** @use HasFactory<WebhookLogFactory> */
    use HasFactory;

    /** The migration creates the singular `webhook_log`; override Laravel's pluralization. */
    protected $table = 'webhook_log';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'gateway',
        'event_type',
        'event_id',
        'status',
        'received_at',
        'payload',
        'signature',
        'processed_at',
        'error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        // payload is cast to array for structured access in handlers.
        // The raw JSON string is preserved in DB for replay and auditing.
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected static function newFactory(): WebhookLogFactory
    {
        return WebhookLogFactory::new();
    }

    /**
     * Mark this webhook as being processed by the async job.
     */
    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark this webhook as successfully processed.
     */
    public function markProcessed(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'processed_at' => now(),
            'error' => null,
        ]);
    }

    /**
     * Mark this webhook as failed with a human-readable error description.
     * Does NOT set processed_at — a failed entry remains retryable.
     */
    public function markFailed(string $error): void
    {
        $this->update(['status' => self::STATUS_FAILED, 'error' => $error]);
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function hasFailed(): bool
    {
        return $this->error !== null && $this->processed_at === null;
    }
}
