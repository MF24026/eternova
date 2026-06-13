<!DOCTYPE html>
<html lang="es">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Cotización {{ $vm->quotationNumber }}</title>
    <style>
        /* ─── Reset ──────────────────────────────────────────────────────────── */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* ─── Page margins ───────────────────────────────────────────────────── */
        /* DomPDF does NOT fully honour box-sizing: border-box, so padding on a
           width:100% container overflows the printable area (clips the right edge).
           Page insets MUST come from the @page margin, not container padding. */
        @page {
            margin: 40px 44px;
        }

        /* ─── Document base ──────────────────────────────────────────────────── */
        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 11px;
            color: #3d2f32;
            background: #ffffff;
            line-height: 1.5;
        }

        /* ─── Layout ─────────────────────────────────────────────────────────── */
        /* No width/padding here — the @page margin defines the content box and a
           block-level div fills it exactly. Adding either re-introduces overflow. */
        .page {
        }

        /* ─── Header ─────────────────────────────────────────────────────────── */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 28px;
        }
        .header-brand {
            width: 55%;
            vertical-align: top;
            padding-right: 20px;
        }
        .header-meta {
            width: 45%;
            vertical-align: top;
            text-align: right;
        }
        .logo {
            max-height: 64px;
            max-width: 200px;
        }
        .business-name {
            font-size: 22px;
            font-weight: bold;
            color: {{ $vm->primaryColor }};
            letter-spacing: -0.5px;
        }
        .tagline {
            font-size: 10px;
            color: #8a7a7d;
            margin-top: 2px;
        }
        .contact-block {
            font-size: 10px;
            color: #6b5a5d;
            margin-top: 6px;
            line-height: 1.6;
        }

        /* ─── Document title box ─────────────────────────────────────────────── */
        .doc-title {
            font-size: 26px;
            font-weight: bold;
            color: {{ $vm->primaryColor }};
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .doc-number {
            font-size: 12px;
            font-weight: bold;
            color: #3d2f32;
            margin-top: 4px;
        }
        .meta-table {
            margin-top: 8px;
            border-collapse: collapse;
        }
        .meta-table td {
            font-size: 10px;
            padding: 2px 0;
        }
        .meta-label {
            color: #8a7a7d;
            padding-right: 8px;
        }
        .meta-value {
            color: #3d2f32;
            font-weight: bold;
        }

        /* ─── Status badge ───────────────────────────────────────────────────── */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: {{ $vm->primaryColor }};
            color: #ffffff;
            margin-top: 6px;
        }

        /* ─── Divider ────────────────────────────────────────────────────────── */
        .divider {
            border: none;
            border-top: 2px solid {{ $vm->primaryColor }};
            margin: 20px 0;
        }
        .divider-light {
            border: none;
            border-top: 1px solid #f0e8e9;
            margin: 16px 0;
        }

        /* ─── Parties (Emisor / Cliente) ─────────────────────────────────────── */
        .parties-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .party-cell {
            width: 50%;
            vertical-align: top;
            padding-right: 20px;
        }
        .party-cell:last-child {
            padding-right: 0;
        }
        .party-heading {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: {{ $vm->primaryColor }};
            margin-bottom: 4px;
        }
        .party-name {
            font-size: 12px;
            font-weight: bold;
            color: #3d2f32;
        }
        .party-detail {
            font-size: 10px;
            color: #6b5a5d;
            line-height: 1.6;
        }
        .no-customer {
            font-size: 10px;
            color: #aaa;
            font-style: italic;
        }

        /* ─── Line items table ───────────────────────────────────────────────── */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .items-table thead tr {
            background-color: {{ $vm->primaryColor }};
        }
        .items-table thead th {
            color: #ffffff;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 10px;
            text-align: left;
        }
        .items-table thead th.right {
            text-align: right;
        }
        .items-table tbody tr:nth-child(even) {
            background-color: #fdf5f6;
        }
        .items-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }
        .items-table tbody td {
            padding: 8px 10px;
            font-size: 11px;
            color: #3d2f32;
            vertical-align: top;
        }
        .items-table tbody td.right {
            text-align: right;
        }
        .items-table tbody td.center {
            text-align: center;
        }
        .col-desc { width: 48%; }
        .col-qty  { width: 10%; }
        .col-price { width: 21%; }
        .col-total { width: 21%; }

        /* ─── Totals ─────────────────────────────────────────────────────────── */
        .totals-wrapper {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-spacer { width: 55%; }
        .totals-box {
            width: 45%;
            vertical-align: top;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px 10px;
            font-size: 11px;
        }
        .totals-table .total-label {
            text-align: left;
            color: #6b5a5d;
        }
        .totals-table .total-value {
            text-align: right;
            color: #3d2f32;
            font-weight: bold;
        }
        .totals-table .grand-total td {
            font-size: 13px;
            font-weight: bold;
            color: #ffffff;
            background-color: {{ $vm->primaryColor }};
            padding: 7px 10px;
        }

        /* ─── Notes / Terms ──────────────────────────────────────────────────── */
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 28px;
        }
        .footer-cell {
            width: 50%;
            vertical-align: top;
            padding-right: 16px;
        }
        .footer-cell:last-child {
            padding-right: 0;
        }
        .footer-heading {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: {{ $vm->primaryColor }};
            margin-bottom: 4px;
        }
        .footer-body {
            font-size: 10px;
            color: #6b5a5d;
            line-height: 1.6;
        }

        /* ─── Page footer ────────────────────────────────────────────────────── */
        .doc-footer {
            margin-top: 32px;
            font-size: 9px;
            color: #c0a8ab;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="page">

    {{-- ═══ Header ═══════════════════════════════════════════════════════════ --}}
    <table class="header-table">
        <tr>
            {{-- Brand side --}}
            <td class="header-brand">
                @if($vm->tenantLogoBase64)
                    <img src="{{ $vm->tenantLogoBase64 }}" class="logo" alt="{{ $vm->tenantName }}"/>
                @else
                    <div class="business-name">{{ $vm->tenantName }}</div>
                @endif

                @if($vm->tenantLogoBase64)
                    <div class="business-name" style="font-size:13px; margin-top:4px;">{{ $vm->tenantName }}</div>
                @endif

                @if($vm->tenantTagline)
                    <div class="tagline">{{ $vm->tenantTagline }}</div>
                @endif

                @if($vm->tenantAddress || $vm->tenantPhone)
                    <div class="contact-block">
                        @if($vm->tenantAddress){{ $vm->tenantAddress }}@endif
                        @if($vm->tenantAddress && $vm->tenantPhone)<br>@endif
                        @if($vm->tenantPhone){{ $vm->tenantPhone }}@endif
                    </div>
                @endif
            </td>

            {{-- Document title + meta --}}
            <td class="header-meta">
                <div class="doc-title">Cotización</div>
                <div class="doc-number"># {{ $vm->quotationNumber }}</div>
                <table class="meta-table">
                    <tr>
                        <td class="meta-label">Fecha:</td>
                        <td class="meta-value">{{ $vm->issueDate }}</td>
                    </tr>
                    @if($vm->validUntil)
                    <tr>
                        <td class="meta-label">Válido hasta:</td>
                        <td class="meta-value">{{ $vm->validUntil }}</td>
                    </tr>
                    @endif
                </table>
                <div class="status-badge">{{ $vm->statusLabel }}</div>
            </td>
        </tr>
    </table>

    <hr class="divider"/>

    {{-- ═══ Parties ═══════════════════════════════════════════════════════════ --}}
    <table class="parties-table">
        <tr>
            {{-- Emisor --}}
            <td class="party-cell">
                <div class="party-heading">Emisor</div>
                <div class="party-name">{{ $vm->tenantName }}</div>
                @if($vm->tenantAddress)
                    <div class="party-detail">{{ $vm->tenantAddress }}</div>
                @endif
                @if($vm->tenantPhone)
                    <div class="party-detail">{{ $vm->tenantPhone }}</div>
                @endif
            </td>

            {{-- Cliente --}}
            <td class="party-cell">
                <div class="party-heading">Cliente</div>
                @if($vm->customerName)
                    <div class="party-name">{{ $vm->customerName }}</div>
                    @if($vm->customerEmail)
                        <div class="party-detail">{{ $vm->customerEmail }}</div>
                    @endif
                    @if($vm->customerPhone)
                        <div class="party-detail">{{ $vm->customerPhone }}</div>
                    @endif
                @else
                    <div class="no-customer">Sin cliente asignado</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ═══ Line items ═════════════════════════════════════════════════════════ --}}
    <table class="items-table">
        <thead>
            <tr>
                <th class="col-desc">Descripción</th>
                <th class="col-qty right">Cant.</th>
                <th class="col-price right">Precio unitario</th>
                <th class="col-total right">Total línea</th>
            </tr>
        </thead>
        <tbody>
            @foreach($vm->items as $item)
            <tr>
                <td class="col-desc">{{ $item['description'] }}</td>
                <td class="col-qty center">{{ $item['quantity'] }}</td>
                <td class="col-price right">{{ $item['unit_price'] }}</td>
                <td class="col-total right">{{ $item['line_total'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ═══ Totals ══════════════════════════════════════════════════════════════ --}}
    <table class="totals-wrapper">
        <tr>
            <td class="totals-spacer"></td>
            <td class="totals-box">
                <table class="totals-table">
                    <tr>
                        <td class="total-label">Subtotal</td>
                        <td class="total-value">{{ $vm->subtotal }}</td>
                    </tr>
                    @if($vm->discount)
                    <tr>
                        <td class="total-label">Descuento</td>
                        <td class="total-value">- {{ $vm->discount }}</td>
                    </tr>
                    @endif
                    @if($vm->taxLabel && $vm->tax)
                    <tr>
                        <td class="total-label">{{ $vm->taxLabel }}</td>
                        <td class="total-value">{{ $vm->tax }}</td>
                    </tr>
                    @endif
                    <tr class="grand-total">
                        <td class="total-label">Total</td>
                        <td class="total-value">{{ $vm->total }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ═══ Notes & Terms ═══════════════════════════════════════════════════════ --}}
    @if($vm->notes || $vm->terms)
        <hr class="divider-light"/>
        <table class="footer-table">
            @if($vm->notes)
            <tr>
                <td class="footer-cell">
                    <div class="footer-heading">Notas</div>
                    <div class="footer-body">{{ $vm->notes }}</div>
                </td>
                @if($vm->terms)
                <td class="footer-cell">
                    <div class="footer-heading">Términos y condiciones</div>
                    <div class="footer-body">{{ $vm->terms }}</div>
                </td>
                @endif
            </tr>
            @elseif($vm->terms)
            <tr>
                <td class="footer-cell" colspan="2">
                    <div class="footer-heading">Términos y condiciones</div>
                    <div class="footer-body">{{ $vm->terms }}</div>
                </td>
            </tr>
            @endif
        </table>
    @endif

    {{-- ═══ Doc footer ══════════════════════════════════════════════════════════ --}}
    <div class="doc-footer">
        {{ $vm->tenantName }} &mdash; {{ $vm->quotationNumber }}
        &bull; Documento generado el {{ now()->format('d/m/Y') }}
    </div>

</div>
</body>
</html>
