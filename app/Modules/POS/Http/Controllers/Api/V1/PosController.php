<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Repositories\CustomerRepositoryInterface;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\POS\Http\Requests\PosCheckoutRequest;
use App\Modules\POS\Http\Resources\PosOrderResource;
use App\Modules\POS\Http\Resources\PosProductCollection;
use App\Modules\POS\Http\Resources\PosReceiptResource;
use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Branch;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * POS REST endpoints consumed by the internal terminal (issue #67).
 *
 * DomainException handling:
 *   OrderService::createFromPos() throws DomainException when a variant has
 *   insufficient stock. bootstrap/app.php has NO DomainException renderer and
 *   MUST NOT be modified, so we catch it here and return a 422 JSON response.
 *
 * Authorization:
 *   Uses named Gate abilities (pos.use, pos.checkout, pos.receipt) registered
 *   in PosServiceProvider. No policy class needed — POS actions have no model.
 *   Double-layer is preserved: PosCheckoutRequest::authorize() gates early;
 *   $this->authorize() applies the full Gate check in each controller action.
 */
final class PosController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /**
     * Product grid for the POS terminal with per-branch exact stock quantities.
     *
     * Query parameters:
     *   - branch_id      (required) — which branch's stock to resolve
     *   - search         (string)   — matches product name, sku_root, or variant sku
     *   - category_slug  (string)   — filter by category slug
     *   - per_page       (int)      — page size, 1–100, default 20
     *
     * Stock exposure: exact available_quantity per variant — unlike the storefront
     * which only exposes in_stock/low_stock booleans.
     */
    public function products(Request $request): PosProductCollection
    {
        $this->authorize('pos.use');

        $request->validate([
            'branch_id' => ['required', 'string'],
            'search' => ['nullable', 'string'],
            'category_slug' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = (string) $request->query('branch_id');
        $branch = Branch::find($branchId);

        if ($branch === null || $branch->tenant_id !== current_tenant()?->id) {
            abort(422, 'The selected branch does not belong to this tenant.');
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', '20')));

        $query = Product::query()
            ->where('is_active', true)
            ->with([
                'variants' => static fn ($q) => $q->orderBy('position'),
                'categories:id,name,slug',
            ]);

        $this->applySearchFilter($query, $request->query('search'));
        $this->applyCategoryFilter($query, $request->query('category_slug'));

        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        /** @var list<int> $variantIds */
        $variantIds = $paginated->pluck('variants')->flatten()->pluck('id')->all();

        $stockMap = $this->buildStockMapForBranch($branch, $variantIds);

        $tax = BranchSetting::resolvedGroup('tax', $branch->id);

        return (new PosProductCollection($paginated))
            ->additional([
                'stock' => $stockMap,
                'tax' => [
                    'enabled' => (bool) ($tax['enabled'] ?? false),
                    'rate_bps' => (int) ($tax['rate_bps'] ?? 0),
                    'prices_include_tax' => (bool) ($tax['prices_include_tax'] ?? false),
                ],
            ]);
    }

    /**
     * POS checkout: create an order atomically and deduct inventory.
     *
     * Calls OrderService::createFromPos(). DomainException (insufficient stock
     * or variant not found in tenant) is surfaced as 422 with a clear message.
     * Returns 201 with PosOrderResource on success.
     */
    public function checkout(PosCheckoutRequest $request): JsonResponse
    {
        $this->authorize('pos.checkout');

        $data = $request->validated();

        $branch = Branch::findOrFail($data['branch_id']);

        /** @var list<array{product_variant_id: int, quantity: int}> $items */
        $items = $data['items'];

        $customer = isset($data['customer_id'])
            ? $this->customers->find((int) $data['customer_id'])
            : null;

        $user = $request->user();

        try {
            $order = $this->orderService->createFromPos(
                branch: $branch,
                items: $items,
                paymentMethod: $data['payment_method'],
                customer: $customer,
                user: $user,
                notes: $data['notes'] ?? null,
                amountReceivedCents: isset($data['amount_received_cents'])
                    ? (int) $data['amount_received_cents']
                    : null,
            );
        } catch (DomainException $e) {
            Log::warning('POS checkout failed — domain error', [
                'message' => $e->getMessage(),
                'branch_id' => $branch->id,
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'pos.checkout_failed',
            ], 422);
        }

        $order->load(['items', 'customer']);

        return (new PosOrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Receipt data for a completed order.
     *
     * Route-model binding resolves Order through BelongsToTenant scope — orders
     * belonging to other tenants are automatically 404.
     */
    public function receipt(Request $request, Order $order): PosReceiptResource
    {
        $this->authorize('pos.receipt');

        $order->load(['branch.tenant', 'customer', 'user', 'items']);

        return new PosReceiptResource($order);
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * @param  Builder<Product>  $query
     */
    private function applySearchFilter(Builder $query, mixed $search): void
    {
        if (! is_string($search) || $search === '') {
            return;
        }

        $term = $search;

        $query->where(static function (Builder $q) use ($term): void {
            $q->where('products.name', 'like', "%{$term}%")
                ->orWhere('products.sku_root', 'like', "%{$term}%")
                ->orWhereExists(static function ($sub) use ($term): void {
                    $sub->from('product_variants')
                        ->whereColumn('product_variants.product_id', 'products.id')
                        ->where('product_variants.sku', 'like', "%{$term}%")
                        ->whereNull('product_variants.deleted_at');
                });
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyCategoryFilter(Builder $query, mixed $categorySlug): void
    {
        if (! is_string($categorySlug) || $categorySlug === '') {
            return;
        }

        $category = Category::where('slug', $categorySlug)
            ->where('is_active', true)
            ->first();

        if ($category === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereExists(static function ($sub) use ($category): void {
            $sub->from('category_product')
                ->whereColumn('category_product.product_id', 'products.id')
                ->where('category_product.category_id', $category->id);
        });
    }

    /**
     * Build a stock map for the given branch, scoped to the provided variant ids.
     *
     * One query replaces N queries (one per variant). Returns
     * Collection<int, int> keyed by product_variant_id → available quantity.
     *
     * @param  list<int>  $variantIds
     * @return Collection<int, int>
     */
    private function buildStockMapForBranch(Branch $branch, array $variantIds): Collection
    {
        if (empty($variantIds)) {
            return collect();
        }

        return BranchInventory::query()
            ->where('branch_id', $branch->id)
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('available', 'product_variant_id');
    }
}
