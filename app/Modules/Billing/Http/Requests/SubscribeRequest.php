<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ];
    }
}
