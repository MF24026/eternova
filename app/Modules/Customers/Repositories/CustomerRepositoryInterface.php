<?php

declare(strict_types=1);

namespace App\Modules\Customers\Repositories;

use App\Modules\Customers\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface
{
    /**
     * Return a paginated, tenant-scoped list of customers.
     *
     * Supported filter keys:
     *   - search (string): matches name LIKE, phone LIKE, whatsapp LIKE, email LIKE
     *   - per_page (int): results per page, clamped to 1–100, default 20
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Customer>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Find a customer by id within the current tenant scope.
     * Returns null when not found (BelongsToTenant scope enforces tenant isolation).
     */
    public function find(int $id): ?Customer;

    /**
     * Persist a new customer row.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer;

    /**
     * Persist changes to an existing customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer;

    /**
     * Soft-delete a customer.
     */
    public function delete(Customer $customer): void;

    /**
     * Restore a soft-deleted customer.
     */
    public function restore(Customer $customer): void;
}
