<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Quotations\Http\Requests\StoreQuotationRequest;
use App\Modules\Quotations\Http\Requests\TransitionQuotationRequest;
use App\Modules\Quotations\Http\Requests\UpdateQuotationRequest;
use App\Modules\Quotations\Http\Resources\QuotationCollection;
use App\Modules\Quotations\Http\Resources\QuotationResource;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Pdf\QuotationPdfRenderer;
use App\Modules\Quotations\Repositories\QuotationRepositoryInterface;
use App\Modules\Quotations\Services\QuotationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Quotation management REST endpoints.
 *
 * Thin controller — every business rule lives in QuotationService.
 * This class only: authorizes, resolves inputs, calls the service, and
 * returns the right HTTP response.
 *
 * DomainException handling:
 *   QuotationService throws DomainException for invalid state transitions
 *   and draft-only enforcement on update. We catch those here and return
 *   422 JSON. All other exceptions bubble to the global handler in
 *   bootstrap/app.php.
 *
 * Route-model binding:
 *   Laravel resolves {quotation} via the BelongsToTenant global scope, so any
 *   id belonging to a different tenant naturally 404s before we get here.
 */
final class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotationService,
        private readonly QuotationRepositoryInterface $quotations,
        private readonly QuotationPdfRenderer $pdfRenderer,
    ) {}

    /**
     * Paginated list of quotations with per-status counts for tab rendering.
     *
     * Query params:
     *   ?status=       — filter to one status (counts always span all statuses)
     *   ?customer_id=  — filter to a specific customer
     *   ?date_from=    — ISO date lower bound on issue_date
     *   ?date_to=      — ISO date upper bound on issue_date
     *   ?search=       — LIKE match on quotation_number or customer name
     *   ?per_page=     — page size (1–100, default 20)
     */
    public function index(Request $request): QuotationCollection
    {
        $this->authorize('viewAny', Quotation::class);

        $filters = [
            'status' => $request->query('status'),
            'customer_id' => $request->query('customer_id'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', '20'),
        ];

        // Eager-load customer to avoid N+1 on the list page.
        $paginator = $this->quotations->paginate($filters);
        $paginator->getCollection()->load(['customer']);

        // Status counts honor all filters EXCEPT status + per_page, so tabs always show
        // correct totals regardless of which tab (status) is active.
        $countsFilters = array_diff_key($filters, ['status' => true, 'per_page' => true]);
        $statusCounts = $this->quotations->statusCounts($countsFilters);

        return (new QuotationCollection($paginator))
            ->additional(['status_counts' => $statusCounts]);
    }

    /**
     * Full quotation detail including items, customer, branch, creator, and status history.
     *
     * Route-model binding resolves the quotation through BelongsToTenant — cross-tenant
     * ids are automatically 404.
     */
    public function show(Quotation $quotation): QuotationResource
    {
        $this->authorize('view', $quotation);

        $quotation->load([
            'items',
            'customer',
            'branch',
            'creator',
            'statusHistory.user',
        ]);

        return new QuotationResource($quotation);
    }

    /**
     * Create a new quotation for the current tenant.
     *
     * Delegates to QuotationService::create() which claims a sequence number,
     * creates the quotation, persists line items, and writes the initial history
     * row — all atomically.
     */
    public function store(StoreQuotationRequest $request): QuotationResource|JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $quotation = $this->quotationService->create(
            data: $request->validated(),
            actor: $request->user(),
        );

        $quotation->load(['items', 'customer', 'statusHistory.user']);

        return (new QuotationResource($quotation))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update a draft quotation's scalar fields and line items.
     *
     * Line items are replaced wholesale — the caller must send the full desired
     * item list on every update. The service enforces that only draft quotations
     * may be edited; a non-draft returns 422.
     */
    public function update(UpdateQuotationRequest $request, Quotation $quotation): QuotationResource|JsonResponse
    {
        $this->authorize('update', $quotation);

        try {
            $updated = $this->quotationService->update(
                quotation: $quotation,
                data: $request->validated(),
                actor: $request->user(),
            );
        } catch (DomainException $e) {
            Log::warning('Quotation update rejected', [
                'quotation_id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'current_status' => $quotation->status,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'quotations.not_editable',
            ], 422);
        }

        $updated->load(['items', 'customer', 'statusHistory.user']);

        return new QuotationResource($updated);
    }

    /**
     * Soft-delete the quotation.
     *
     * The BelongsToTenant global scope ensures only the current tenant's
     * quotations are accessible — cross-tenant ids 404 before reaching here.
     */
    public function destroy(Quotation $quotation): Response
    {
        $this->authorize('delete', $quotation);

        $this->quotations->delete($quotation);

        Log::info('Quotation deleted', [
            'quotation_id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'tenant_id' => $quotation->tenant_id,
        ]);

        return response()->noContent();
    }

    /**
     * Render and stream the quotation as a PDF document.
     *
     * The PDF is rendered on-demand (no disk cache in v1 — cheap to regenerate).
     * Content-Disposition: inline so the browser opens the PDF in a new tab
     * without forcing a download dialog — the E8 "preview" button depends on this.
     *
     * Filename: cotizacion-{quotation_number}.pdf (hyphens, lowercase, safe for any OS).
     *
     * Authorization: requires 'view' on the quotation — same gate as show().
     * Cross-tenant ids are already 404 via BelongsToTenant route-model binding.
     */
    public function pdf(Quotation $quotation): SymfonyResponse
    {
        $this->authorize('view', $quotation);

        $bytes = $this->pdfRenderer->render($quotation);
        $filename = 'cotizacion-'.str_replace('/', '-', $quotation->quotation_number).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => strlen($bytes),
        ]);
    }

    /**
     * Mark the quotation as sent to the customer (draft → sent).
     *
     * An optional note is stored in the status history. Invalid transitions
     * (e.g. trying to send an already-accepted quotation) return 422 with
     * the DomainException message so the UI can surface it.
     */
    public function send(TransitionQuotationRequest $request, Quotation $quotation): QuotationResource|JsonResponse
    {
        $this->authorize('update', $quotation);

        $note = $request->validated()['note'] ?? null;

        try {
            $updated = $this->quotationService->markSent(
                quotation: $quotation,
                actor: $request->user(),
                note: $note,
            );
        } catch (DomainException $e) {
            Log::warning('Quotation send rejected', [
                'quotation_id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'current_status' => $quotation->status,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'quotations.invalid_transition',
            ], 422);
        }

        $updated->load(['items', 'customer', 'statusHistory.user']);

        return new QuotationResource($updated);
    }

    /**
     * Accept the quotation (draft|sent → accepted).
     *
     * When convert_to_order: true is passed in the body, the acceptance and order
     * creation are executed in a single transaction. The response will include
     * converted_order_id populated. Invalid transitions or already-converted
     * quotations return 422 with the DomainException message.
     */
    public function accept(TransitionQuotationRequest $request, Quotation $quotation): QuotationResource|JsonResponse
    {
        $this->authorize('update', $quotation);

        $validated = $request->validated();
        $note = $validated['note'] ?? null;
        $convertToOrder = (bool) ($validated['convert_to_order'] ?? false);

        try {
            $updated = $this->quotationService->accept(
                quotation: $quotation,
                actor: $request->user(),
                convertToOrder: $convertToOrder,
                note: $note,
            );
        } catch (DomainException $e) {
            Log::warning('Quotation accept rejected', [
                'quotation_id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'current_status' => $quotation->status,
                'convert_to_order' => $convertToOrder,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'quotations.invalid_transition',
            ], 422);
        }

        $updated->load(['items', 'customer', 'statusHistory.user']);

        return new QuotationResource($updated);
    }

    /**
     * Reject the quotation (draft|sent → rejected).
     *
     * Invalid transitions (e.g. already-rejected, or accepted) return 422.
     */
    public function reject(TransitionQuotationRequest $request, Quotation $quotation): QuotationResource|JsonResponse
    {
        $this->authorize('update', $quotation);

        $note = $request->validated()['note'] ?? null;

        try {
            $updated = $this->quotationService->reject(
                quotation: $quotation,
                actor: $request->user(),
                note: $note,
            );
        } catch (DomainException $e) {
            Log::warning('Quotation reject rejected', [
                'quotation_id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'current_status' => $quotation->status,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'quotations.invalid_transition',
            ], 422);
        }

        $updated->load(['items', 'customer', 'statusHistory.user']);

        return new QuotationResource($updated);
    }
}
