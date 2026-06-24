<?php

declare(strict_types=1);

namespace App\Support\TaxId\Rules;

use App\Support\TaxId\TaxIdStrategyFactory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a fiscal id against the strategy for a given country. Empty values
 * pass (the field stays optional — nullability is enforced by the sibling
 * 'nullable' rule); only a present-but-malformed document fails.
 */
final class ValidTaxId implements ValidationRule
{
    public function __construct(private readonly string $countryCode) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! TaxIdStrategyFactory::for($this->countryCode)->isValid((string) $value)) {
            $fail('El número de identificación fiscal no es válido para el país configurado.');
        }
    }
}
