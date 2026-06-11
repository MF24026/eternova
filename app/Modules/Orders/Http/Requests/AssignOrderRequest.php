<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input shape for the order assignee endpoint.
 *
 * Accepts a nullable user id. Passing null clears the assignment.
 * Cross-tenant validation (the user must belong to this tenant) is enforced by
 * OrderService::assign() — that check requires a Tenant lookup that belongs in
 * the service layer, not in a Form Request rule.
 */
final class AssignOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],
        ];
    }
}
