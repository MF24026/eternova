<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for super-admin billing actions: a meaningful reason is mandatory (it lands in
 * the append-only audit log) and super-admin is re-checked here too.
 */
class OperatorReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_super_admin);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
