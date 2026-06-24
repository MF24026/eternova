@php
    use App\Support\Money\Format;

    $taxIdentity = $taxIdentity ?? null;
    $brand = config('saas.name', 'Eternova');
    // Locale drives grouping/decimals; the currency is the invoice's own snapshot
    // (never derived from the tenant — see i18n billing rule).
    $locale = $invoice->tenant ? Format::localeForTenant($invoice->tenant) : 'es-SV';
    $currency = $invoice->currency ?: 'USD';
    $money = static fn (int $cents): string => Format::number($cents, $currency, $locale);
    $plan = $invoice->subscription?->plan;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #3d2f32; font-size: 12px; }
        .header { width: 100%; border-bottom: 2px solid #7c545d; padding-bottom: 12px; }
        .brand { font-size: 22px; color: #7c545d; font-weight: bold; }
        .meta { margin-top: 18px; width: 100%; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .label { color: #8a7a7d; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.lines th { text-align: left; background: #f4ecec; padding: 8px; color: #5a4b71; }
        table.lines td { padding: 8px; border-bottom: 1px solid #efe5e5; }
        .right { text-align: right; }
        .totals { margin-top: 16px; width: 40%; float: right; }
        .totals td { padding: 4px 8px; }
        .total-row td { font-weight: bold; border-top: 2px solid #7c545d; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 999px; background: #e6f4ea; color: #2f7d4f; }
    </style>
</head>
<body>
    <div class="header">
        <span class="brand">{{ $brand }}</span>
    </div>

    <table class="meta">
        <tr>
            <td>
                <div class="label">Facturado a</div>
                <div>{{ $invoice->tenant?->business_name ?? $invoice->tenant?->name }}</div>
                <div>{{ $invoice->tenant?->email }}</div>
                @if ($taxIdentity)
                    <div>{{ $taxIdentity['label'] }}: {{ $taxIdentity['number'] }}</div>
                @endif
            </td>
            <td class="right">
                <div class="label">Factura</div>
                <div>{{ $invoice->number }}</div>
                <div class="label" style="margin-top:8px">Fecha</div>
                <div>{{ optional($invoice->paid_at ?? $invoice->created_at)->format('Y-m-d') }}</div>
                <div style="margin-top:8px"><span class="status">{{ ucfirst($invoice->status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="right">Monto ({{ $invoice->currency }})</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Suscripcion{{ $plan ? ' - '.$plan->name : '' }}</td>
                <td class="right">{{ $money($invoice->subtotal_cents) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="right">{{ $money($invoice->subtotal_cents) }}</td>
        </tr>
        <tr>
            <td class="label">Impuestos</td>
            <td class="right">{{ $money($invoice->tax_cents) }}</td>
        </tr>
        <tr class="total-row">
            <td>Total</td>
            <td class="right">{{ $invoice->currency }} {{ $money($invoice->total_cents) }}</td>
        </tr>
    </table>
</body>
</html>
