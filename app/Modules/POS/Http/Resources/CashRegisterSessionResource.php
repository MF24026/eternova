<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\POS\Models\CashRegisterSession;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @property CashRegisterSession $resource
 */
final class CashRegisterSessionResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var CashRegisterSession $session */
        $session = $this->resource;

        return [
            'id' => $session->id,
            'branch_id' => $session->branch_id,
            'user_id' => $session->user_id,
            'session_number' => $session->session_number,
            'status' => $session->status,
            'opening_amount_cents' => $session->opening_amount_cents,
            'closing_amount_cents' => $session->closing_amount_cents,
            'expected_amount_cents' => $session->expected_amount_cents,
            'difference_cents' => $session->difference_cents,
            // Live breakdown for the arqueo view.
            'cash_sales_cents' => $session->cash_sales_cents,
            'card_sales_cents' => $session->card_sales_cents,
            'transfer_sales_cents' => $session->transfer_sales_cents,
            'total_sales_cents' => $session->total_sales_cents,
            'order_count' => $session->order_count,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'opening_notes' => $session->opening_notes,
            'closing_notes' => $session->closing_notes,
        ];
    }
}
