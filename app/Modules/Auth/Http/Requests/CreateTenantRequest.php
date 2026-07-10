<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Tenancy\Enums\BusinessType;
use App\Modules\Tenancy\Support\SlugValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user can provision a tenant for themselves
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'unique:tenants,slug',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        $fail('The slug must be a string.');

                        return;
                    }

                    if (! SlugValidator::isValid($value)) {
                        $fail('The slug must be 3–63 lowercase alphanumeric characters or hyphens, and cannot start or end with a hyphen.');

                        return;
                    }

                    if (SlugValidator::isReserved($value)) {
                        $fail('The slug "'.$value.'" is reserved and cannot be used.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'language' => ['required', 'string', 'min:2', 'max:5'],
            'timezone' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        $fail('The timezone must be a string.');

                        return;
                    }

                    if (! in_array($value, timezone_identifiers_list(), strict: true)) {
                        $fail('The timezone "'.$value.'" is not a valid timezone identifier.');
                    }
                },
            ],
            // plan_slug format validation only — existence is validated in TenantProvisioner
            // so that invalid plan slugs cause a transactional rollback (not a pre-flight 422).
            'plan_slug' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Business vertical (giro) chosen in onboarding. Single source of truth:
            // it persists on the tenant AND derives the starter catalog (config/verticals.php).
            'business_type' => ['sometimes', Rule::enum(BusinessType::class)],
        ];
    }
}
