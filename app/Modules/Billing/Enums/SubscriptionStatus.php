<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * The 9 states a subscription can be in. Exactly one at any time — the `status`
 * column on the subscriptions row is the single source of truth.
 *
 * Spellings `trialing` and `canceled` are kept from the original 4-state ERD stub so
 * existing rows, factory states, SubscriptionService and SubscriptionObserver keep
 * working without a data migration. The five remaining states are new to Phase 1.
 *
 * Transition rules do NOT live here — they live in the State pattern classes under
 * App\Modules\Billing\Domain\States. This enum is just the vocabulary.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';         // signup grace period, no charge yet
    case Active = 'active';             // paid + current
    case PastDue = 'past_due';          // last charge failed, in dunning
    case Paused = 'paused';             // tenant requested pause (optional)
    case Canceled = 'canceled';         // tenant cancelled, may still be in grace period
    case Expired = 'expired';           // trial ended without a payment method
    case Suspended = 'suspended';       // dunning exhausted, account read-only
    case SoftDeleted = 'soft_deleted';  // grace ended, billing data preserved for retention
    case HardDeleted = 'hard_deleted';  // retention elapsed, PII purged

    /**
     * Spanish UI label (the product UI is Spanish; code stays English).
     */
    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Prueba',
            self::Active => 'Activa',
            self::PastDue => 'Pago pendiente',
            self::Paused => 'Pausada',
            self::Canceled => 'Cancelada',
            self::Expired => 'Expirada',
            self::Suspended => 'Suspendida',
            self::SoftDeleted => 'Eliminada',
            self::HardDeleted => 'Purgada',
        };
    }
}
