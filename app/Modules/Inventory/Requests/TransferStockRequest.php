<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;

final class TransferStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate checked via policy in the controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_branch_id' => [
                'required',
                'string',
                'different:to_branch_id',
                $this->branchBelongsToCurrentTenant('from_branch_id'),
            ],
            'to_branch_id' => [
                'required',
                'string',
                'different:from_branch_id',
                $this->branchBelongsToCurrentTenant('to_branch_id'),
            ],
            'product_variant_id' => [
                'required',
                'integer',
                $this->variantBelongsToCurrentTenant(),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_branch_id.different' => 'Source and destination branches must be different.',
            'to_branch_id.different' => 'Source and destination branches must be different.',
        ];
    }

    private function branchBelongsToCurrentTenant(string $field): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $tenant = current_tenant();

            if ($tenant === null) {
                $fail('No tenant context resolved.');

                return;
            }

            $exists = Branch::withoutGlobalScope(TenantScope::class)
                ->where('id', $value)
                ->where('tenant_id', $tenant->id)
                ->exists();

            if (! $exists) {
                $fail('The selected branch does not belong to your organization.');
            }
        };
    }

    private function variantBelongsToCurrentTenant(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $tenant = current_tenant();

            if ($tenant === null) {
                $fail('No tenant context resolved.');

                return;
            }

            $exists = ProductVariant::whereHas(
                'product',
                fn ($q) => $q->withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenant->id)
            )->where('id', (int) $value)->exists();

            if (! $exists) {
                $fail('The selected product variant does not belong to your catalog.');
            }
        };
    }
}
