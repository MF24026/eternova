<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Http\Resources;

use App\Http\Resources\Api\V1\BaseCollection;
use Illuminate\Http\Request;

/**
 * Paginated collection of quotations.
 *
 * The index endpoint augments the standard pagination meta with a
 * status_counts object so the frontend can render tab counters without a
 * separate round-trip. Counts are injected via ->additional() in the controller.
 *
 * @see QuotationController::index()
 */
final class QuotationCollection extends BaseCollection
{
    public $collects = QuotationResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
