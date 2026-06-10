<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Requests;

use App\Modules\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates the POS checkout payload.
 *
 * Authorization is double-layered:
 *   1. FormRequest::authorize() — fast-path gate check using the pos.checkout
 *      ability. Rejects unauthenticated / low-privilege callers before validation.
 *   2. Controller calls $this->authorize('pos.checkout') — explicit second gate
 *      check that runs after form resolution. (#32 double-layer pattern)
 *
 * The branch_id must exist AND belong to the current tenant. We validate this
 * with a closure rule — a single-use constraint that does not merit a Rule class.
 */
final class PosCheckoutRequest extends FormRequest
{
    /**
     * Early authorization check.
     */
    public function authorize(): bool
    {
        return Gate::allows('pos.checkout');
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
                Rule::exists('branches', 'id'),
                $this->branchBelongsToCurrentTenant(),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->whereNull('deleted_at'),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Closure rule: the branch must belong to the current tenant.
     */
    private function branchBelongsToCurrentTenant(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $tenant = current_tenant();

            if ($tenant === null) {
                $fail('No tenant context is active for this request.');

                return;
            }

            $exists = Branch::where('id', $value)
                ->where('tenant_id', $tenant->id)
                ->exists();

            if (! $exists) {
                $fail('The selected branch does not belong to this tenant.');
            }
        };
    }
}
