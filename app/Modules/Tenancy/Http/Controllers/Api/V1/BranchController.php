<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Http\Resources\BranchResource;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only branch listing for the current tenant. Consumed by the POS terminal
 * (branch selector + per-branch stock) and the inventory module. The
 * BelongsToTenant global scope already restricts results to the current tenant,
 * so no extra tenant filtering is required here.
 */
final class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Branch::query();

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        $branches = $query
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }
}
