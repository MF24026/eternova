<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Modules\Tenancy\Models\Tenant;
use NumberFormatter;

/**
 * Locale-aware money formatting for the backend (PDFs, emails, anywhere the
 * frontend `useFormatCurrency` composable can't reach).
 *
 * Input is always an integer in the currency's MINOR unit (what every `_cents`
 * column stores). The currency's decimals decide how that integer is split into
 * major/fractional parts, so a zero-decimal currency (COP/CLP) never renders a
 * spurious ".00" and a two-decimal one always keeps its cents.
 *
 *   Format::forCurrency(123456, 'USD', 'es-SV')  // "$1,234.56"
 *   Format::forCurrency(150000, 'COP', 'es-CO')  // "$ 150.000"
 *   Format::cents($invoice->total_cents, $tenant) // resolves currency + locale
 *
 * NumberFormatter instances are cached per (locale, decimals) — they are not free
 * to build and a single PDF/report can format dozens of amounts.
 */
final class Format
{
    /** @var array<string, NumberFormatter> */
    private static array $cache = [];

    /**
     * Format a minor-unit amount in an explicit currency + locale. This is the
     * workhorse; the other helpers resolve their currency/locale then delegate.
     */
    public static function forCurrency(int $cents, string $currencyCode, ?string $locale = null): string
    {
        $code = strtoupper($currencyCode !== '' ? $currencyCode : 'USD');
        $decimals = Currency::decimalsFor($code);
        $amount = $cents / (10 ** $decimals);

        return self::formatter($locale ?? 'es-SV', $decimals)->formatCurrency($amount, $code);
    }

    /**
     * Format a minor-unit amount as a bare localized number (no symbol, no code),
     * respecting the currency's decimals and the locale's grouping/decimal glyphs.
     *
     * Used by formal documents (invoice/quotation PDFs) that prefix the ISO code
     * themselves — "$" alone is ambiguous across USD/SV/CO/MX, so those documents
     * print "USD 1,234.56" / "COP 150.000" instead of a symbol.
     */
    public static function number(int $cents, string $currencyCode, string $locale = 'es-SV'): string
    {
        $decimals = Currency::decimalsFor($currencyCode !== '' ? $currencyCode : 'USD');
        $amount = $cents / (10 ** $decimals);

        return self::decimalFormatter($locale, $decimals)->format($amount);
    }

    /**
     * Format a minor-unit amount in the tenant's currency + locale. Falls back to
     * the bound current tenant, then to USD/es-SV when no tenant is resolvable
     * (e.g. a queued job without tenant context).
     */
    public static function cents(int $cents, ?Tenant $tenant = null): string
    {
        $tenant ??= self::currentTenant();

        if ($tenant === null) {
            return self::forCurrency($cents, 'USD', 'es-SV');
        }

        return self::forCurrency(
            $cents,
            (string) ($tenant->currency ?? 'USD'),
            self::localeForTenant($tenant),
        );
    }

    /**
     * Build an ICU locale from the tenant's language + country (es + CO -> es-CO),
     * which drives grouping/decimal separators per region.
     */
    public static function localeForTenant(Tenant $tenant): string
    {
        $language = (string) ($tenant->language ?? '') ?: 'es';
        $country = (string) ($tenant->country_code ?? '');

        return $country !== '' ? "{$language}-{$country}" : $language;
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    private static function currentTenant(): ?Tenant
    {
        $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private static function formatter(string $locale, int $decimals): NumberFormatter
    {
        return self::cached("cur:{$locale}:{$decimals}", $locale, NumberFormatter::CURRENCY, $decimals);
    }

    private static function decimalFormatter(string $locale, int $decimals): NumberFormatter
    {
        return self::cached("dec:{$locale}:{$decimals}", $locale, NumberFormatter::DECIMAL, $decimals);
    }

    private static function cached(string $key, string $locale, int $style, int $decimals): NumberFormatter
    {
        if (! isset(self::$cache[$key])) {
            $formatter = new NumberFormatter($locale, $style);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            self::$cache[$key] = $formatter;
        }

        return self::$cache[$key];
    }
}
