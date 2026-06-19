<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Services\TenantBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tenant invoice list + PDF download. Owner-only. Every lookup goes through
 * TenantBillingService which filters by tenant_id, so a tenant can never read or download
 * another tenant's invoice (a foreign/unknown id 404s).
 */
final class InvoiceController extends Controller
{
    public function __construct(private readonly TenantBillingService $billing) {}

    public function index(): JsonResponse
    {
        $this->authorize('billing.manage');

        $tenant = current_tenant();
        abort_if($tenant === null, 404);

        return response()->json([
            'data' => InvoiceResource::collection($this->billing->invoices($tenant->id)),
        ]);
    }

    public function download(int $invoice): StreamedResponse
    {
        $this->authorize('billing.manage');

        $tenant = current_tenant();
        abort_if($tenant === null, 404);

        $record = $this->billing->findInvoice($tenant->id, $invoice);
        abort_if($record === null, 404);
        abort_if($record->pdf_url === null || ! Storage::exists($record->pdf_url), 404);

        return Storage::download($record->pdf_url, "{$record->number}.pdf");
    }
}
