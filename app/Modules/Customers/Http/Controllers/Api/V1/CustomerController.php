<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerCollection;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Repositories\CustomerRepositoryInterface;
use App\Modules\Customers\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly CustomerService $customerService,
    ) {}

    /**
     * Paginated customer list with optional search — powers the POS customer selector.
     *
     * Query params:
     *   ?search=   — matches name, phone, whatsapp, or email
     *   ?per_page= — results per page (1–100, default 20)
     */
    public function index(Request $request): CustomerCollection
    {
        $this->authorize('viewAny', Customer::class);

        $filters = [
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', '20'),
        ];

        return new CustomerCollection(
            $this->customers->paginate($filters)
        );
    }

    /**
     * Show a single customer.
     */
    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customer);
    }

    /**
     * Create a new customer.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $this->customerService->create($request->validated());

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing customer.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('update', $customer);

        $updated = $this->customerService->update($customer, $request->validated());

        return new CustomerResource($updated);
    }

    /**
     * Soft-delete a customer.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->customerService->delete($customer);

        return response()->json(null, 204);
    }

    /**
     * Restore a soft-deleted customer.
     */
    public function restore(Customer $customer): CustomerResource
    {
        $this->authorize('restore', $customer);

        $this->customerService->restore($customer);

        return new CustomerResource($customer->fresh() ?? $customer);
    }
}
