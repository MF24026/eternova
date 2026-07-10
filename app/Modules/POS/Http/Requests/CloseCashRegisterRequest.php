<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class CloseCashRegisterRequest extends FormRequest
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
            'closing_amount_cents' => ['required', 'integer', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
