<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Http\Resources\TeamMemberResource;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only listing of the current tenant's members, consumed by staff-assignment
 * selectors (e.g. the order assignee picker in Orders).
 *
 * Users are a global registry (no tenant_id column); membership lives in the
 * tenant_users pivot. We resolve the current tenant from the container — bound by
 * the EnsureTenant middleware — and return its members with their per-tenant role.
 */
final class TeamController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        /** @var Tenant $tenant */
        $tenant = app('currentTenant');

        $members = $tenant->users()
            ->orderBy('name')
            ->get();

        return TeamMemberResource::collection($members);
    }
}
