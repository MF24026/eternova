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

    /** @var list<string> */
    protected $fillable = [
        'gateway',
        'event_type',
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
        'processed_at' => 'datetime',
    ];

    protected static function newFactory(): WebhookLogFactory
    {
        return WebhookLogFactory::new();
    }

    /**
     * Mark this webhook as successfully processed.
     */
    public function markProcessed(): void
    {
        $this->update([
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
        $this->update(['error' => $error]);
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
