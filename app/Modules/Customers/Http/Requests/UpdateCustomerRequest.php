<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Rules\ValidPhoneForCountry;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return $customer !== null
            && ($this->user()?->can('update', $customer) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', new ValidPhoneForCountry],
            'whatsapp' => ['nullable', 'string', 'max:30', new ValidPhoneForCountry],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
