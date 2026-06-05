<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant attempts to use a feature that is not included in their
 * current subscription plan. The exception carries the feature identifier plus
 * the plans involved so that the API exception handler can compose a rich 402
 * response body with upgrade-prompting metadata.
 */
final class PlanGateException extends RuntimeException
{
    private function __construct(
        public readonly string $feature,
        public readonly string $currentPlan,
        public readonly string $requiredPlan,
    ) {
        parent::__construct(
            "The feature \"{$feature}\" requires the \"{$requiredPlan}\" plan. Current plan: \"{$currentPlan}\".",
        );
    }

    /**
     * Named constructor — preferred entry point.
     *
     * Example:
     *   throw PlanGateException::for('multi_branch_inventory', 'basico', 'pro');
     */
    public static function for(
        string $feature,
        string $currentPlan,
        string $requiredPlan,
    ): self {
        return new self($feature, $currentPlan, $requiredPlan);
    }
}
