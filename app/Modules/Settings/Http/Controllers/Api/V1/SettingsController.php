<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant Settings endpoints.
 *
 * Thin controller — all group→backend routing lives in SettingsService.
 *
 *   GET  /api/v1/settings          → resolved settings for every group + catalog meta
 *   POST /api/v1/settings/{group}  → persist one group (POST, not PUT, so the brand
 *                                    group can carry multipart logo/favicon uploads)
 *
 * Authorization: owner/admin only, via the 'settings.view' / 'settings.manage' gates.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('settings.view');

        $tenant = current_tenant();

        return response()->json([
            'data' => $this->settings->resolveAll($tenant),
            'meta' => [
                'groups'  => SettingsService::groups(),
                'catalog' => $this->settings->catalog(),
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request, string $group): JsonResponse
    {
        Gate::authorize('settings.manage');

        if (! SettingsService::isKnownGroup($group)) {
            abort(404, "Unknown settings group '{$group}'.");
        }

        $tenant = current_tenant();

        $this->settings->updateGroup(
            tenant: $tenant,
            group: $group,
            data: $request->validated(),
            files: [
                'logo'    => $request->file('logo'),
                'favicon' => $request->file('favicon'),
            ],
        );

        return response()->json([
            'data' => $this->settings->resolveAll($tenant->refresh()),
        ]);
    }
}
