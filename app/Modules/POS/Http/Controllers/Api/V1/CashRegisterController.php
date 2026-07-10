<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\POS\Http\Requests\CloseCashRegisterRequest;
use App\Modules\POS\Http\Requests\OpenCashRegisterRequest;
use App\Modules\POS\Http\Resources\CashRegisterSessionResource;
use App\Modules\POS\Models\CashRegisterSession;
use App\Modules\POS\Services\CashRegisterService;
use App\Modules\Tenancy\Models\Branch;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cash-register session endpoints (arqueo). Gated by module:cash_register (the tenant's
 * giro must enable it) + the pos.cash_register gate (POS staff). Thin: delegates to
 * CashRegisterService.
 */
final class CashRegisterController extends Controller
{
    public function __construct(private readonly CashRegisterService $service) {}

    /** The cashier's open session at a branch, or null. */
    public function current(Request $request): JsonResponse
    {
        $branch = $this->resolveBranch((string) $request->query('branch_id', ''));

        /** @var User $cashier */
        $cashier = $request->user();
        $session = $this->service->currentFor($branch, $cashier);

        return response()->json([
            'data' => $session !== null ? new CashRegisterSessionResource($session) : null,
        ]);
    }

    public function open(OpenCashRegisterRequest $request): JsonResponse
    {
        $branch = $this->resolveBranch((string) $request->validated('branch_id'));

        /** @var User $cashier */
        $cashier = $request->user();

        try {
            $session = $this->service->open(
                $branch,
                $cashier,
                (int) $request->validated('opening_amount_cents'),
                $request->validated('opening_notes'),
            );
        } catch (DomainException $e) {
            abort(422, $e->getMessage());
        }

        return (new CashRegisterSessionResource($session))->response()->setStatusCode(201);
    }

    public function close(CloseCashRegisterRequest $request, CashRegisterSession $session): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $closed = $this->service->close(
                $session,
                (int) $request->validated('closing_amount_cents'),
                $request->validated('closing_notes'),
                $actor,
            );
        } catch (DomainException $e) {
            abort(422, $e->getMessage());
        }

        return (new CashRegisterSessionResource($closed))->response();
    }

    private function resolveBranch(string $branchId): Branch
    {
        $branch = Branch::find($branchId);

        if ($branch === null || $branch->tenant_id !== current_tenant()?->id) {
            abort(422, 'La sucursal no pertenece a este negocio.');
        }

        return $branch;
    }
}
