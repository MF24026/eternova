<?php

declare(strict_types=1);

namespace App\Modules\Customers\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a phone number has the correct digit count for the current
 * tenant's country.
 *
 * Approach: digit-count only (not full libphonenumber). Strip all non-digit
 * characters first, then compare against the expected digit count for the
 * resolved country_code. This is intentionally lenient: numbers formatted as
 * "7654-3210", "+503 7654 3210", or "76543210" all pass for SV.
 *
 * Empty / null values pass — callers must add a `required` rule separately
 * when the field is mandatory. This rule only triggers when a non-empty value
 * is provided.
 *
 * Supported countries and their expected digit counts:
 *   SV — El Salvador  : 8 digits  (e.g. 7654-3210)
 *   GT — Guatemala    : 8 digits  (e.g. 5555-1234)
 *   HN — Honduras     : 8 digits  (e.g. 9987-6543)
 *   CO — Colombia     : 10 digits (e.g. 300 123 4567)
 *   MX — Mexico       : 10 digits (e.g. 55 1234 5678)
 *   US — United States: 10 digits (e.g. 213 555 0100)
 *   CR — Costa Rica   : 8 digits  (e.g. 8888-1234)
 *   EC — Ecuador      : 9 digits  (e.g. 099 123 4567)
 *   PE — Peru         : 9 digits  (e.g. 987 654 321)
 *   CL — Chile        : 9 digits  (e.g. 9 8765 4321)
 *   AR — Argentina    : 10 digits (mobile) or 10 digits (landline+area)
 *
 * Countries not in the list fall through to the GenericPhone validator which
 * accepts 7–15 digits (ITU-T E.164 range).
 */
final class ValidPhoneForCountry implements ValidationRule
{
    /**
     * Expected digit count by ISO 3166-1 alpha-2 country code.
     *
     * @var array<string, int>
     */
    private const DIGIT_COUNTS = [
        'SV' => 8,
        'GT' => 8,
        'HN' => 8,
        'CR' => 8,
        'CO' => 10,
        'MX' => 10,
        'US' => 10,
        'AR' => 10,
        'EC' => 9,
        'PE' => 9,
        'CL' => 9,
    ];

    private const MIN_GENERIC = 7;

    private const MAX_GENERIC = 15;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === null || $digits === '') {
            $fail('The :attribute must contain at least one digit.');

            return;
        }

        $tenant = current_tenant();
        $countryCode = $tenant?->country_code;

        if ($countryCode !== null && isset(self::DIGIT_COUNTS[$countryCode])) {
            $expected = self::DIGIT_COUNTS[$countryCode];

            if (strlen($digits) !== $expected) {
                $fail(
                    "The :attribute must be {$expected} digits for {$countryCode} "
                    .'(got '.strlen($digits).').'
                );
            }

            return;
        }

        // Fallback for unsupported / unknown countries: accept ITU-T E.164 range.
        $len = strlen($digits);
        if ($len < self::MIN_GENERIC || $len > self::MAX_GENERIC) {
            $fail(
                'The :attribute must be between '.self::MIN_GENERIC.' and '
                .self::MAX_GENERIC.' digits.'
            );
        }
    }
}
