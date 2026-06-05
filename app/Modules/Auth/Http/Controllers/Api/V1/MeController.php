<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    /**
     * Return the currently authenticated user with their tenant memberships.
     *
     * The tenants array includes all tenants the user belongs to, with their
     * per-tenant role. The current tenant (if any) is flagged is_current: true.
     */
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('tenants');

        return new UserResource($user);
    }
}
