<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a gateway call fails in a way the caller cannot turn into a ChargeResult
 * (network error, unexpected provider response). Deliberately carries NO previous exception
 * and only a safe message — the original throwable may reference a card token in its trace,
 * so we never chain it.
 */
final class GatewayException extends RuntimeException {}
