<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\CreateTenantRequest;
use App\Modules\Auth\Services\TenantProvisioner;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use Illuminate\Http\JsonResponse;

final class TenantProvisionController extends Controller
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
    ) {}

    /**
     * Provision a new tenant for the authenticated user.
     *
     * Creates: Tenant + Branch (main) + Subscription (trialing) + tenant_users (owner).
     * All four in one DB transaction — if any step fails, none of them persist.
     *
     * The user becomes the owner of the new tenant automatically.
     * This endpoint is the backend for the onboarding wizard (issue #13).
     */
    public function __invoke(CreateTenantRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tenant = $this->provisioner->provision($user, $request->validated());

        $tenant->load(['branches', 'subscriptions.plan']);

        return (new TenantResource($tenant))
            ->response()
            ->setStatusCode(201);
    }
}
