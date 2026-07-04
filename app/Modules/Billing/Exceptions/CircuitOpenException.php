<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown by the CircuitBreaker when a call type has failed past its threshold and is in
 * cooldown. Callers (dunning, recurring charges) treat this as "skip and retry later",
 * not as a hard payment failure.
 */
final class CircuitOpenException extends RuntimeException {}
