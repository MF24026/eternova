---
name: laravel-saas-i18n-latam
description: Use when building a SaaS targeting Latin America (multi-country) — Colombia, Mexico, Argentina, Chile, Peru, El Salvador, Costa Rica, etc. Captures country picker, money/date formatting per locale, phone input strategy per country, tax ID input (NIT, RFC, CUIT, RUT, etc.), ESLint rule against hardcoded $ and dates, IP-based country detection with manual override. The doctrine: every country is different — currency symbol, decimal separator, phone format, tax ID rules. Get it wrong and your SaaS reads as "made for one country, awkward in others."
---

# Laravel SaaS i18n LatAm

You are building a SaaS for Latin America. **Each country is its own universe**: Colombia uses COP with $ symbol but NO decimal places, Mexico uses MXN with $ but 2 decimals, Argentina uses ARS with $ AND has its own tax ID format. Get it wrong and locals will reject your product as "not for us." This skill captures the cross-cutting i18n patterns that worked.

**Origin:** A Laravel SaaS launched in El Salvador, expanded to Colombia, Mexico, Argentina, Chile, Costa Rica. Each new country surfaced a new edge: COP has no cents, RFC has check digit, AR phones drop the 9 in calling format, Chile uses dot as thousands separator. **Doing this incrementally per-country = pain. Doing it as a system = sustainable.**

## When to use this skill

Activate when:
- Starting a SaaS that will target multiple LatAm countries
- Adding a new country to an existing SaaS
- Building signup / onboarding (where country is chosen)
- Showing currency anywhere (POS, invoice, reports)
- Showing dates anywhere
- Asking for a phone number
- Asking for a tax ID (NIT, RFC, etc.)
- Sending emails (footer locale, currency in invoice)
- Wondering "should `$` mean USD or local currency?"

## The countries — initial scope

Pick the countries you'll launch with. Don't claim "LatAm" if you only handle Colombia. Common starting set:

| Code | Country | Currency | Locale | Decimals | Phone format | Tax ID |
|---|---|---|---|---|---|---|
| `SV` | El Salvador | USD | `es_SV` | 2 | +503 XXXX-XXXX | DUI / NIT |
| `CO` | Colombia | COP | `es_CO` | 0 | +57 XXX XXX XXXX | NIT |
| `MX` | Mexico | MXN | `es_MX` | 2 | +52 XX XXXX XXXX | RFC |
| `AR` | Argentina | ARS | `es_AR` | 2 | +54 9 XX XXXX-XXXX | CUIT/CUIL |
| `CL` | Chile | CLP | `es_CL` | 0 | +56 9 XXXX XXXX | RUT |
| `PE` | Peru | PEN | `es_PE` | 2 | +51 9XX XXX XXX | RUC/DNI |
| `CR` | Costa Rica | CRC | `es_CR` | 2 | +506 XXXX-XXXX | Cédula |
| `EC` | Ecuador | USD | `es_EC` | 2 | +593 9X XXX XXXX | RUC/Cédula |
| `GT` | Guatemala | GTQ | `es_GT` | 2 | +502 XXXX-XXXX | NIT |
| `US` | USA | USD | `en_US` | 2 | +1 XXX-XXX-XXXX | EIN/SSN |

## The schema — Country on Tenant

```php
Schema::create('tenants', function (Blueprint $table) {
    // ...
    $table->string('country_code', 2)->index();   // ISO 3166-1 alpha-2
    $table->string('currency', 3);                 // ISO 4217
    $table->string('locale', 5);                   // e.g. 'es_CO'
    $table->unsignedTinyInteger('currency_decimals')->default(2);
    $table->string('timezone', 50)->default('UTC');
    // ...
});
```

**Why store decimals separately**: even though currency → decimals is mostly deterministic (COP=0, MXN=2), there are edge cases (some tenants want forced display). Store explicitly.

## Country picker — at signup, mandatory

The signup form has a country picker as FIRST field (before email even). The choice cascades:

- Locks `currency`, `locale`, `decimals` defaults
- Sets `phone` input strategy
- Sets `tax_id` input strategy + label
- Sets timezone default (Colombia → America/Bogota, Mexico → America/Mexico_City, etc.)
- Drives marketing copy locale on the rest of the funnel

### IP detection with manual override

On the marketing site / signup landing, detect country from IP via a free service (ipapi.co, ip-api.com, Cloudflare's `CF-IPCountry` header). **Pre-select but don't lock** — show "We detected Colombia. Change?" so users on VPN can pick correctly.

**Bypass for testing**: when local dev (`APP_ENV=local`), expose `?country=MX` query param that overrides IP detection. Document this in onboarding docs.

```php
final class CountryDetector
{
    public function detect(Request $request): string
    {
        if (app()->environment('local') && $request->has('country')) {
            return strtoupper($request->string('country')->value());
        }
        $header = $request->header('CF-IPCountry');
        if ($header && $header !== 'XX') return strtoupper($header);
        return 'US'; // safe default
    }
}
```

## Money — the most-violated thing in LatAm SaaS

### Backend helper

```php
namespace App\Support;

final class Money
{
    public static function format(int $cents, string $currency, string $locale): string
    {
        $decimals = self::decimalsFor($currency);
        $amount = $cents / (10 ** $decimals);
        return (new \NumberFormatter($locale, \NumberFormatter::CURRENCY))
            ->formatCurrency($amount, $currency);
    }

    public static function decimalsFor(string $currency): int
    {
        return match ($currency) {
            'COP', 'CLP', 'JPY' => 0,
            default => 2,
        };
    }
}
```

Usage:
```php
Money::format(150000, 'COP', 'es_CO'); // "$ 150.000" (no decimals)
Money::format(150000, 'MXN', 'es_MX'); // "$1,500.00"
Money::format(150000, 'USD', 'en_US'); // "$1,500.00"
```

### Frontend helper (Vue)

```js
// resources/js/Helpers/formatMoney.js
const decimalsByCurrency = { COP: 0, CLP: 0, JPY: 0 };

export function formatMoney(cents, currency, locale) {
  const decimals = decimalsByCurrency[currency] ?? 2;
  const amount = cents / (10 ** decimals);
  return new Intl.NumberFormat(locale.replace('_', '-'), {
    style: 'currency',
    currency,
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(amount);
}
```

**Composable**: `useMoney()` reads currency + locale from the Inertia shared `current_tenant` props.

```js
// useMoney.js
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { formatMoney } from '@/Helpers/formatMoney';

export function useMoney() {
  const page = usePage();
  return {
    format: (cents) => formatMoney(
      cents,
      page.props.current_tenant?.currency ?? 'USD',
      page.props.current_tenant?.locale ?? 'en_US',
    ),
  };
}
```

Usage in templates:
```vue
<script setup>
import { useMoney } from '@/composables/useMoney';
const { format } = useMoney();
</script>
<template>
  <span>{{ format(item.price_cents) }}</span>
</template>
```

### Anti-hardcoded sweep

Add an ESLint rule (or CI grep) that fails the build if it finds `$` followed by a digit, or hardcoded currency formatting:

```bash
# CI step
! grep -rnE '\$[0-9]' --include='*.vue' --include='*.js' resources/js/ \
  || (echo 'Hardcoded $ symbol found — use useMoney() composable' && exit 1)
```

## Dates — locale-aware

```php
namespace App\Support;

final class DateFormatter
{
    public static function format(CarbonImmutable $date, string $locale, string $format = 'short'): string
    {
        $fmt = match ($format) {
            'short'  => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long'   => \IntlDateFormatter::LONG,
        };
        return (new \IntlDateFormatter($locale, $fmt, \IntlDateFormatter::SHORT))
            ->format($date);
    }
}
```

Frontend equivalent: `useDate()` composable wrapping `Intl.DateTimeFormat`.

**Carbon locale must match tenant locale**: `Carbon::setLocale($tenant->locale)` at the start of every request. Do it in middleware.

## Phone input — strategy per country

```php
interface PhoneInputStrategy
{
    public function placeholder(): string;
    public function mask(): string;       // for client-side input mask
    public function validate(string $raw): bool;
    public function normalize(string $raw): string; // E.164 format: +57XXXXXXXXXX
}

final class ColombianPhoneStrategy implements PhoneInputStrategy
{
    public function placeholder(): string { return '300 123 4567'; }
    public function mask(): string { return '000 000 0000'; }
    public function validate(string $raw): bool { return preg_match('/^3\d{9}$/', preg_replace('/\D/', '', $raw)) === 1; }
    public function normalize(string $raw): string { return '+57' . preg_replace('/\D/', '', $raw); }
}

final class MexicanPhoneStrategy implements PhoneInputStrategy { /* +52 */ }
final class ArgentinePhoneStrategy implements PhoneInputStrategy {
    // SPECIAL: AR mobile drops the leading 9 in calling format
    // local: 11 1234-5678 → calling: +54 9 11 1234-5678
}
```

Factory:
```php
final class PhoneStrategyFactory
{
    public static function for(string $countryCode): PhoneInputStrategy
    {
        return match ($countryCode) {
            'CO' => new ColombianPhoneStrategy(),
            'MX' => new MexicanPhoneStrategy(),
            'AR' => new ArgentinePhoneStrategy(),
            // ...
            default => new GenericPhoneStrategy(),
        };
    }
}
```

### Vue component

```vue
<!-- PhoneInput.vue -->
<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useMask } from '@/composables/useMask';

const props = defineProps({ modelValue: String });
const emit = defineEmits(['update:modelValue']);

const page = usePage();
const country = computed(() => page.props.current_tenant?.country_code ?? 'US');
const strategy = computed(() => phoneStrategies[country.value] ?? phoneStrategies.US);
</script>
<template>
  <input
    :placeholder="strategy.placeholder"
    :value="modelValue"
    @input="emit('update:modelValue', $event.target.value)"
    v-mask="strategy.mask"
  />
</template>
```

## Tax ID input — Strategy per country

```php
interface TaxIdStrategy
{
    public function label(): string;        // "NIT", "RFC", "CUIT", "RUT"
    public function placeholder(): string;
    public function validate(string $raw): bool;
    public function format(string $raw): string;  // pretty display
}

final class ColombianNitStrategy implements TaxIdStrategy
{
    public function label(): string { return 'NIT'; }
    public function placeholder(): string { return '900.123.456-7'; }
    public function validate(string $raw): bool
    {
        $clean = preg_replace('/\D/', '', $raw);
        if (strlen($clean) < 9 || strlen($clean) > 10) return false;
        // Check digit validation
        return $this->verifyCheckDigit($clean);
    }
    // ...
}

final class MexicanRfcStrategy implements TaxIdStrategy
{
    public function label(): string { return 'RFC'; }
    public function placeholder(): string { return 'AAAA000101AAA'; }
    public function validate(string $raw): bool
    {
        return preg_match('/^[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}$/', strtoupper($raw)) === 1;
    }
}

// CUIT, RUT, RUC, DUI, etc.
```

## Backfilling existing tenants

If you add multi-country support after launch, backfill currency/locale/decimals for existing tenants:

```php
// database/migrations/YYYY_MM_DD_backfill_tenant_country_data.php
public function up(): void
{
    DB::statement("
        UPDATE tenants
        SET currency = 'USD', locale = 'en_US', currency_decimals = 2
        WHERE currency IS NULL
    ");
}
```

Then make the columns `NOT NULL` after the backfill.

## Currency drift in invoices

**A subscription started in 2024 at $100 USD must still show $100 USD on its 2026 invoice**, even if the tenant has since changed to COP. Store the currency on EACH transaction (invoice, charge, refund), not derive from tenant on-the-fly:

```php
Schema::create('invoices', function (Blueprint $table) {
    // ...
    $table->string('currency', 3); // snapshot at time of invoice, NOT joined from tenant
    $table->unsignedInteger('amount_cents');
    // ...
});
```

## Email locale

Notifications are sent in the tenant's locale, NOT the recipient's. So if a Mexican tenant has an employee, all emails to that employee come in `es_MX`. The tenant's locale wins.

```php
final class TenantAwareMailable extends Mailable
{
    public function build(): self
    {
        $tenant = app('current_tenant');
        $this->locale($tenant->locale);
        $this->from($tenant->email_from ?? 'noreply@yourdomain.com', $tenant->name);
        $this->withSymfonyMessage(function ($msg) use ($tenant) {
            $msg->getHeaders()->addTextHeader('X-Tenant-Id', (string) $tenant->id);
            $msg->getHeaders()->addTextHeader('X-Tenant-Locale', $tenant->locale);
        });
        return $this;
    }
}
```

## Pricing page localization

The marketing /pricing page must show prices in the visitor's likely currency:
- Default to USD for global / unknown
- IP-detect → show local currency with conversion (e.g. "$59 USD ≈ COP 240,000")
- Per-country conversion rates updated daily via a cron pulling from a rates API
- "Prices in {currency}" disclaimer at the bottom

## Anti-patterns — never do this

- Hardcoding `$` symbol anywhere — use the locale-aware formatter
- Hardcoding `.` or `,` as decimal separator — locale decides
- Using `number_format()` directly — it's locale-blind. Use `NumberFormatter`.
- Storing money as float — always integer cents (or local minor unit)
- Showing dates in `Y-m-d` format universally — locale decides MM/DD/YYYY vs DD/MM/YYYY
- Assuming all phones are 10 digits — not in AR (11+) or CL (8+1)
- Validating tax IDs with a regex copied from Stack Overflow without check-digit verification
- Sending emails in English to a Mexican tenant
- Pricing page in USD only on a LatAm SaaS site
- Forcing one timezone on all tenants (UTC) — they need their local time for reports
- Auto-detecting country and HIDING the picker — users on VPN, ex-pats, or shared offices need to override
- Treating Spanish as monolithic — `es_MX` ≠ `es_AR` ≠ `es_CO` (some idioms, dates, formal/informal you, etc.)

## Cross-references

- `laravel-saas-multi-tenant-foundation` — `country_code`, `currency`, `locale` columns on the tenants table
- `laravel-saas-billing-infrastructure` — currency snapshot on invoices/charges (don't derive from tenant)
- `vue-inertia-frontend-system` — `useMoney()`, `useDate()`, `PhoneInput` composable patterns
- `senior-dev-code-style` — Strategy pattern application
