<?php

declare(strict_types=1);

namespace App\Modules\Customers\Repositories;

use App\Modules\Customers\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentCustomerRepository implements CustomerRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Customer>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(1, min(100, $perPage));

        $query = Customer::query()->orderBy('name');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = (string) $filters['search'];
            $query->where(static function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('whatsapp', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): ?Customer
    {
        return Customer::find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->fresh() ?? $customer;
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    public function restore(Customer $customer): void
    {
        $customer->restore();
    }
}
