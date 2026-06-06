<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Repositories\InventoryRepositoryInterface;
use App\Modules\Inventory\Requests\RecordMovementRequest;
use App\Modules\Inventory\Requests\TransferStockRequest;
use App\Modules\Inventory\Resources\BranchInventoryCollection;
use App\Modules\Inventory\Resources\BranchInventoryResource;
use App\Modules\Inventory\Resources\InventoryMovementCollection;
use App\Modules\Inventory\Resources\InventoryMovementResource;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryRepositoryInterface $repository,
        private readonly InventoryService $service,
    ) {}

    /**
     * GET /api/v1/inventory
     * Paginated stock listing per branch.
     */
    public function index(Request $request): BranchInventoryCollection
    {
        $this->authorize('viewAny', BranchInventory::class);

        return new BranchInventoryCollection(
            $this->repository->paginate($request->only([
                'branch_id',
                'product_variant_id',
                'low_stock',
                'search',
                'per_page',
            ]))
        );
    }

    /**
     * GET /api/v1/inventory/{inventory}
     * Single BranchInventory row with branch + variant + product.
     */
    public function show(BranchInventory $inventory): BranchInventoryResource
    {
        $this->authorize('view', $inventory);

        $inventory->loadMissing(['branch', 'productVariant.product']);

        return new BranchInventoryResource($inventory);
    }

    /**
     * POST /api/v1/inventory/movements
     * Record an entry, exit, or adjustment movement.
     * Routes to the appropriate InventoryService method based on `type`.
     */
    public function storeMovement(RecordMovementRequest $request): JsonResponse
    {
        $type = $request->validated('type');

        // Enforce role-based authorization per movement type
        if ($type === InventoryMovement::TYPE_ADJUSTMENT) {
            $this->authorize('adjust', BranchInventory::class);
        } else {
            $this->authorize('recordMovement', BranchInventory::class);
        }

        $branch = Branch::findOrFail($request->validated('branch_id'));
        $variant = ProductVariant::findOrFail($request->validated('product_variant_id'));
        $qty = (int) $request->validated('quantity');
        $notes = $request->validated('notes');
        $user = $request->user();

        $movement = match ($type) {
            InventoryMovement::TYPE_ENTRY => $this->service->recordEntry(
                branch: $branch,
                variant: $variant,
                quantity: $qty,
                user: $user,
                notes: $notes,
            ),
            InventoryMovement::TYPE_EXIT => $this->service->recordExit(
                branch: $branch,
                variant: $variant,
                quantity: abs($qty),
                user: $user,
                notes: $notes,
            ),
            InventoryMovement::TYPE_ADJUSTMENT => $this->service->recordAdjustment(
                branch: $branch,
                variant: $variant,
                delta: $qty,
                user: $user,
                notes: $notes,
            ),
        };

        return (new InventoryMovementResource($movement))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/v1/inventory/transfers
     * Transfer stock between two branches of the same tenant.
     */
    public function storeTransfer(TransferStockRequest $request): JsonResponse
    {
        $this->authorize('transfer', BranchInventory::class);

        $fromBranch = Branch::findOrFail($request->validated('from_branch_id'));
        $toBranch = Branch::findOrFail($request->validated('to_branch_id'));
        $variant = ProductVariant::findOrFail($request->validated('product_variant_id'));
        $quantity = (int) $request->validated('quantity');
        $notes = $request->validated('notes');
        $user = $request->user();

        [$exitMovement, $entryMovement] = $this->service->transferBetweenBranches(
            fromBranch: $fromBranch,
            toBranch: $toBranch,
            variant: $variant,
            quantity: $quantity,
            user: $user,
            notes: $notes,
        );

        return response()->json([
            'data' => [
                'exit_movement' => new InventoryMovementResource($exitMovement),
                'entry_movement' => new InventoryMovementResource($entryMovement),
            ],
        ], 201);
    }

    /**
     * GET /api/v1/inventory/movements
     * Paginated movement history with optional filters.
     */
    public function movements(Request $request): InventoryMovementCollection
    {
        $this->authorize('viewAny', BranchInventory::class);

        return new InventoryMovementCollection(
            $this->repository->paginateMovements($request->only([
                'branch_id',
                'product_variant_id',
                'type',
                'from_date',
                'to_date',
                'reference_type',
                'per_page',
            ]))
        );
    }
}
