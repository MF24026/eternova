<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when an idempotent operation with the same key is already running (status
 * 'pending'). The caller should back off and retry, not start a second concurrent attempt
 * — this is what prevents a double charge when two requests race on the same key.
 */
final class IdempotencyInProgressException extends RuntimeException {}
