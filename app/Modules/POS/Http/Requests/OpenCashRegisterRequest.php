<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class OpenCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.cash_register');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'string', tenant_exists('branches')],
            'opening_amount_cents' => ['required', 'integer', 'min:0'],
            'opening_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
