<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Exceptions;

use App\Modules\Billing\Enums\SubscriptionStatus;
use DomainException;

/**
 * Thrown when a caller asks a subscription to move to a state that the current state
 * does not permit (e.g. trying to charge a soft-deleted subscription).
 *
 * Extends DomainException so existing callers that catch DomainException for billing
 * invariants keep catching transition violations too.
 */
final class InvalidSubscriptionTransitionException extends DomainException
{
    public static function between(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self(
            "Invalid subscription transition: {$from->value} cannot transition to {$to->value}."
        );
    }
}
