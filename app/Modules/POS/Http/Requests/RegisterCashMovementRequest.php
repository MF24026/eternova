<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RegisterCashMovementRequest extends FormRequest
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
            'type' => ['required', Rule::in(['in', 'out'])],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
