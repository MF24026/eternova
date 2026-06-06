<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Tag;
use App\Modules\Tenancy\Support\SlugValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Tag::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = current_tenant()?->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! SlugValidator::isValid((string) $value)) {
                        $fail('The slug must be a lowercase alphanumeric slug (RFC 1035).');
                    }
                },
                Rule::unique('tags', 'slug')->where('tenant_id', $tenantId),
            ],
        ];
    }
}
