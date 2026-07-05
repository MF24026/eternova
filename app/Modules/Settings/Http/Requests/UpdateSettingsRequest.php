<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use App\Support\TaxId\Rules\ValidTaxId;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates one settings group. The group comes from the {group} route segment;
 * rules() returns the rule set for that group. The controller authorizes via the
 * 'settings.manage' gate and rejects unknown groups before this runs, so an
 * unmatched group here simply yields an empty rule set.
 *
 * Brand additionally accepts optional `logo` / `favicon` file uploads.
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate check ('settings.manage') happens in the controller.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var string $group */
        $group = (string) $this->route('group');

        $catalog = (array) config('tenant-settings.catalog', []);
        $countries = array_keys((array) ($catalog['countries'] ?? []));
        $currencies = (array) ($catalog['currencies'] ?? []);
        $languages = array_keys((array) ($catalog['languages'] ?? []));

        return match ($group) {
            'brand' => [
                'business_name' => ['required', 'string', 'max:120'],
                'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                // SVG is intentionally NOT accepted: an SVG can carry a <script>
                // that executes when the asset is opened directly (logos are served
                // from the public disk, often by nginx/CDN that bypass our CSP), so
                // a tenant-uploaded SVG is a stored-XSS vector. Raster only.
                'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
                'favicon' => ['nullable', 'image', 'mimes:png,ico', 'max:512'],
            ],
            'locale' => [
                'currency' => ['required', 'string', Rule::in($currencies)],
                'country_code' => ['required', 'string', Rule::in($countries)],
                'language' => ['required', 'string', Rule::in($languages)],
                'timezone' => ['required', 'timezone'],
            ],
            'contact' => [
                'phone' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:150'],
                'website' => ['nullable', 'string', 'max:150'],
                'address' => ['nullable', 'string', 'max:300'],
            ],
            'tax' => [
                'enabled' => ['required', 'boolean'],
                'rate_bps' => ['required', 'integer', 'min:0', 'max:9999'],
                'id_label' => ['nullable', 'string', 'max:20'],
                'id_number' => ['nullable', 'string', 'max:40', new ValidTaxId($this->tenantCountryCode())],
                'prices_include_tax' => ['required', 'boolean'],
            ],
            'orders' => [
                'auto_confirm' => ['required', 'boolean'],
                'default_prep_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
                'pending_alert_hours' => ['required', 'integer', 'min:1', 'max:72'],
            ],
            'quotations' => [
                'quotation_tax_rate_bps' => ['required', 'integer', 'min:0', 'max:9999'],
                'quotation_valid_days' => ['required', 'integer', 'min:1', 'max:365'],
                'quotation_terms' => ['nullable', 'string', 'max:10000'],
            ],
            'reservations' => [
                'reservation_deposit_pct' => ['required', 'integer', 'min:0', 'max:100'],
                'reservation_occasions' => ['nullable', 'array', 'max:50'],
                'reservation_occasions.*' => ['string', 'max:60'],
            ],
            'notifications' => [
                'new_order' => ['required', 'boolean'],
                'order_pending' => ['required', 'boolean'],
                'low_stock' => ['required', 'boolean'],
                'reservation_confirmed' => ['required', 'boolean'],
                'quotation_accepted' => ['required', 'boolean'],
                'payment_received' => ['required', 'boolean'],
            ],
            default => [],
        };
    }

    /**
     * Cast boolean-ish inputs so 'true'/'false'/'1'/'0' from multipart/form-data
     * validate as booleans (multipart sends everything as strings).
     */
    protected function prepareForValidation(): void
    {
        /** @var string $group */
        $group = (string) $this->route('group');

        $booleanKeys = match ($group) {
            'tax' => ['enabled', 'prices_include_tax'],
            'orders' => ['auto_confirm'],
            'notifications' => array_keys((array) config('tenant-settings.defaults.notifications', [])),
            default => [],
        };

        $casts = [];
        foreach ($booleanKeys as $key) {
            if ($this->has($key)) {
                $casts[$key] = filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN);
            }
        }

        if ($casts !== []) {
            $this->merge($casts);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'primary_color.regex' => 'El color debe ser un valor hexadecimal como #7c545d.',
            'secondary_color.regex' => 'El color debe ser un valor hexadecimal como #5a4b71.',
            'currency.in' => 'La moneda seleccionada no está soportada.',
            'country_code.in' => 'El país seleccionado no está soportado.',
            'timezone.timezone' => 'La zona horaria no es válida.',
            'logo.max' => 'El logo no puede superar los 2MB.',
            'favicon.max' => 'El favicon no puede superar los 512KB.',
        ];
    }

    /**
     * The settings group being updated (validated route segment).
     */
    public function group(): string
    {
        return (string) $this->route('group');
    }

    /**
     * Country code of the acting tenant, driving the fiscal-id validation rule.
     * Falls back to SV (the platform's home market) when no tenant is resolved.
     */
    private function tenantCountryCode(): string
    {
        $tenant = current_tenant();

        return is_string($tenant?->country_code) && $tenant->country_code !== ''
            ? $tenant->country_code
            : 'SV';
    }
}
