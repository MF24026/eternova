<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Quotations\Models\QuotationItem;
use Illuminate\Http\Request;

/**
 * Transforms a single QuotationItem line for API output.
 *
 * Monetary values (unit_price_cents, line_total_cents) are exposed as raw
 * centavo integers — formatting for display (currency symbol, decimal places)
 * is the frontend's responsibility via useFormatCurrency.
 *
 * @property QuotationItem $resource
 */
final class QuotationItemResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var QuotationItem $item */
        $item = $this->resource;

        return [
            'id'               => $item->id,
            'product_id'       => $item->product_id,
            'description'      => $item->description,
            'quantity'         => $item->quantity,
            'unit_price_cents' => $item->unit_price_cents,
            'line_total_cents' => $item->line_total_cents,
            'sort_order'       => $item->sort_order,
        ];
    }
}
