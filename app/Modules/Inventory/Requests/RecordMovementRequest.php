<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Requests;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordMovementRequest extends FormRequest
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
            'branch_id' => [
                'required',
                'string',
                $this->branchBelongsToCurrentTenant(),
            ],
            'product_variant_id' => [
                'required',
                'integer',
                $this->variantBelongsToCurrentTenant(),
            ],
            'type' => [
                'required',
                Rule::in([
                    InventoryMovement::TYPE_ENTRY,
                    InventoryMovement::TYPE_EXIT,
                    InventoryMovement::TYPE_ADJUSTMENT,
                ]),
                // Transfer type is handled by TransferStockRequest — excluded here
            ],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Movement type must be one of: entry, exit, adjustment. Use the transfers endpoint for transfers.',
        ];
    }

    private function branchBelongsToCurrentTenant(): \Closure
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

            // ProductVariant has no tenant_id — ownership is established via the Product.
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
