<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Requests;

/**
 * Extend a tenant's trial by N days (bounded). Inherits the mandatory-reason + super-admin gate.
 */
final class ExtendTrialRequest extends OperatorReasonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
    }
}
