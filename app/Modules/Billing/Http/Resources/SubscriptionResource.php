<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
final class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = SubscriptionStatus::from($this->status);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $status->label(),
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
            ]),
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period,
            'trial_ends_at' => $this->trial_ends_at,
            'current_period_end' => $this->current_period_end,
            'next_billing_at' => $this->next_billing_at,
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
            // Display-only payment method metadata. NEVER the token (it is $hidden anyway).
            'card_last4' => $this->card_last4,
            'card_brand' => $this->card_brand,
        ];
    }
}
