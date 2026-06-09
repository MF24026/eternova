<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Repositories\CustomerRepositoryInterface;
use Illuminate\Support\Facades\Log;

/**
 * Phone storage decision: keep the value exactly as the user typed it (formatted).
 * Rationale: the digit-count validation already rejects wrong lengths, so the
 * formatted value ("7654-3210", "300 123 4567") is more readable in listings and
 * the POS selector. Normalization to E.164 is deferred to the WhatsApp/notification
 * module when that integration is built.
 */
final class CustomerService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /**
     * Create a new customer record.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        $customer = $this->customers->create($data);

        Log::info('Customer created', [
            'customer_id' => $customer->id,
            'tenant_id' => $customer->tenant_id,
        ]);

        return $customer;
    }

    /**
     * Update an existing customer record.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $updated = $this->customers->update($customer, $data);

        Log::info('Customer updated', [
            'customer_id' => $updated->id,
            'tenant_id' => $updated->tenant_id,
        ]);

        return $updated;
    }

    /**
     * Soft-delete a customer.
     */
    public function delete(Customer $customer): void
    {
        $this->customers->delete($customer);

        Log::info('Customer soft-deleted', [
            'customer_id' => $customer->id,
            'tenant_id' => $customer->tenant_id,
        ]);
    }

    /**
     * Restore a soft-deleted customer.
     */
    public function restore(Customer $customer): void
    {
        $this->customers->restore($customer);

        Log::info('Customer restored', [
            'customer_id' => $customer->id,
            'tenant_id' => $customer->tenant_id,
        ]);
    }
}
