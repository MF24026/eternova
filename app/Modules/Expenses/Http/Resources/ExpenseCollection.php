<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Resources;

use App\Http\Resources\Api\V1\BaseCollection;
use Illuminate\Http\Request;

/**
 * Paginated collection of expenses.
 *
 * The index endpoint may augment the standard pagination meta with a
 * period_total_cents scalar via ->additional() in the controller so the
 * frontend can display the filtered period's total without a separate request.
 *
 * @see ExpenseController::index()
 */
final class ExpenseCollection extends BaseCollection
{
    public $collects = ExpenseResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
