<?php

declare(strict_types=1);

namespace App\Support\TaxId;

/**
 * Resolves the {@see TaxIdStrategy} for a tenant's country. SV gets the DUI/NIT
 * validator; every other country falls back to the lenient generic one, labelled
 * from the country catalog (NIT for CO, RFC for MX, ...).
 */
final class TaxIdStrategyFactory
{
    public static function for(string $countryCode): TaxIdStrategy
    {
        return match (strtoupper($countryCode)) {
            'SV' => new SalvadoranTaxIdStrategy(),
            default => new GenericTaxIdStrategy(self::labelFor($countryCode)),
        };
    }

    private static function labelFor(string $countryCode): string
    {
        $label = config('tenant-settings.catalog.countries.'.strtoupper($countryCode).'.tax_id_label');

        return is_string($label) && $label !== '' ? $label : 'ID fiscal';
    }
}
