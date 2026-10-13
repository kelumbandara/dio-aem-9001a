@php
    $m = function ($v) {
        $v = (string) $v;
        $neg = str_starts_with($v, '-');
        [$int, $dec] = explode('.', bcadd(ltrim($v, '-'), '0.005', 2));

        return ($neg && bccomp($v, '0', 2) !== 0 ? '-' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int).'.'.$dec;
    };
    $q = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
    $c = $doc['company'];
    $name = $c['trade_name'] ?: $c['legal_name'];
    $incl = $doc['prices_include_vat'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('procurement::ui.print.title') }} {{ $doc['document_no'] }}</title>
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #065f46; --soft: #f1f5f9; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; color: var(--ink); font: 13px/1.45 var(--font, system-ui, sans-serif); }
        .bar { display: flex; gap: 8px; justify-content: center; padding: 12px; }
        .bar a, .bar button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; }
        .bar button { background: var(--accent); color: #fff; border-color: var(--accent); }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto 24px; background: #fff; padding: 14mm 14mm 12mm; position: relative; box-shadow: 0 2px 16px rgba(15,23,42,.15); border-top: 6px solid var(--accent); }
        header { display: flex; justify-content: space-between; gap: 24px; }
        .brand h1 { margin: 0; font-size: 20px; }
        .brand p { margin: 2px 0; color: var(--muted); }
        .brand .tin { color: var(--ink); font-weight: 600; }
        .title { text-align: right; }
        .title h2 { margin: 0; font-size: 22px; letter-spacing: 2px; text-transform: uppercase; color: var(--accent); }
        .title table { margin-left: auto; margin-top: 8px; border-collapse: collapse; }
        .title td { padding: 2px 0 2px 14px; text-align: right; }
        .title td:first-child { color: var(--muted); }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 20px 0 14px; }
        .box { border: 1px solid var(--line); border-radius: 6px; padding: 10px 12px; }
        .box h3 { margin: 0 0 4px; font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--muted); font-weight: 600; }
        .box strong { font-size: 14px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.lines th { background: var(--soft); text-align: left; font-size: 10.5px; letter-spacing: .6px; text-transform: uppercase; color: var(--muted); padding: 7px 8px; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        table.lines td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .code { color: var(--muted); font-size: 11px; }
        .totals { display: flex; justify-content: space-between; gap: 24px; margin-top: 14px; align-items: flex-start; }
        .notes { flex: 1; color: var(--muted); font-size: 12px; }
        .sum { width: 78mm; border-collapse: collapse; }
        .sum td { padding: 4px 8px; }
        .sum tr.grand td { border-top: 2px solid var(--ink); font-size: 15px; font-weight: 700; padding-top: 8px; }
        footer { position: absolute; left: 14mm; right: 14mm; bottom: 12mm; }
        .sign { display: flex; justify-content: space-between; gap: 40px; margin-bottom: 10px; }
        .sign div { flex: 1; border-top: 1px solid var(--ink); padding-top: 4px; color: var(--muted); font-size: 11px; }
        .legal { text-align: center; color: var(--muted); font-size: 10.5px; }
        @page { size: A4; margin: 0; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; } }
    </style>
    <x-core::branding.document-style />
</head>
<body>
<div class="bar">
    <a href="{{ route('procurement.orders.show', $doc['id']) }}">&larr; {{ __('procurement::ui.common.back') }}</a>
    <button type="button" id="print-now">{{ __('procurement::ui.print.print') }}</button>
</div>

<main class="sheet">
    <header>
        <div class="brand">
            <x-core::branding.document-logo />
            <h1>{{ $name }}</h1>
            @if ($c['trade_name'] && $c['trade_name'] !== $c['legal_name'])<p>{{ $c['legal_name'] }}</p>@endif
            @foreach ($c['address'] as $line)<p>{{ $line }}</p>@endforeach
            @if ($c['phone'] || $c['email'])<p>{{ implode(' · ', array_filter([$c['phone'], $c['email']])) }}</p>@endif
            @if ($c['tax_id'])<p class="tin">{{ __('procurement::ui.print.tax_id') }}: {{ $c['tax_id'] }}</p>@endif
        </div>
        <div class="title">
            <h2>{{ __('procurement::ui.print.title') }}</h2>
            <table>
                <tr><td>{{ __('procurement::ui.print.order_no') }}</td><td><strong>{{ $doc['document_no'] }}</strong></td></tr>
                <tr><td>{{ __('procurement::ui.print.date') }}</td><td>{{ $doc['order_date'] }}</td></tr>
                @if ($doc['expected_date'])<tr><td>{{ __('procurement::ui.form.expected_date') }}</td><td>{{ $doc['expected_date'] }}</td></tr>@endif
                @if ($doc['supplier_ref'])<tr><td>{{ __('procurement::ui.form.supplier_ref') }}</td><td>{{ $doc['supplier_ref'] }}</td></tr>@endif
            </table>
        </div>
    </header>

    <section class="parties">
        <div class="box">
            <h3>{{ __('procurement::ui.print.supplier') }}</h3>
            <strong>{{ $doc['vendor']['name'] }}</strong>
            @foreach ($doc['vendor']['address'] as $line)<div>{{ $line }}</div>@endforeach
            @if ($doc['vendor']['tax_id'])<div class="code">{{ __('procurement::ui.print.supplier_tax_id') }}: {{ $doc['vendor']['tax_id'] }}</div>@endif
            <div class="code">{{ $doc['vendor']['code'] }}</div>
        </div>
        <div class="box">
            <h3>{{ __('procurement::ui.print.deliver_to') }}</h3>
            <strong>{{ $doc['warehouse'] ?? '' }}</strong>
            <div class="code">{{ __('procurement::ui.print.terms') }}: {{ $doc['payment_terms_days'] > 0 ? __('procurement::ui.print.net_days', ['days' => $doc['payment_terms_days']]) : __('procurement::ui.print.due_on_receipt') }}</div>
        </div>
    </section>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:24px">#</th><th>{{ __('procurement::ui.print.description') }}</th>
                <th class="r">{{ __('procurement::ui.common.qty') }}</th><th class="r">{{ $incl ? __('procurement::ui.common.unit_price_incl') : __('procurement::ui.common.unit_price') }}</th>
                <th class="r">{{ __('procurement::ui.common.discount') }}</th><th class="r">{{ $incl ? __('procurement::ui.print.line_total_incl') : __('procurement::ui.common.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td>{{ $l['line'] }}</td>
                    <td>{{ $l['description'] ?: $l['item_name'] }} <span class="code">{{ $l['item'] }}</span></td>
                    <td class="r">{{ $q($l['qty']) }} {{ $l['uom'] }}</td>
                    <td class="r">{{ $m($l['unit_price']) }}</td>
                    <td class="r">{{ (float) $l['discount_pct'] > 0 ? $q($l['discount_pct']).'%' : '' }}</td>
                    <td class="r">{{ $m($incl ? bcadd($l['net_amount'], $l['vat_amount'], 4) : $l['net_amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="notes">@if ($doc['notes'])<strong>{{ __('procurement::ui.form.notes') }}</strong><br>{{ $doc['notes'] }}@endif</div>
        <table class="sum">
            <tr><td>{{ __('procurement::ui.common.subtotal') }}</td><td class="r">{{ $m($doc['totals']['net']) }}</td></tr>
            <tr><td>{{ __('procurement::ui.common.vat') }}@if ($incl) ({{ __('procurement::ui.print.included') }})@endif</td><td class="r">{{ $m($doc['totals']['vat']) }}</td></tr>
            <tr class="grand"><td>{{ __('procurement::ui.common.total') }} (LKR)</td><td class="r">{{ $m($doc['totals']['gross']) }}</td></tr>
        </table>
    </div>

    <footer>
        <x-core::branding.document-footer />
        <div class="sign"><div>{{ __('procurement::ui.print.prepared_by') }}</div><div>{{ __('procurement::ui.print.authorised_by') }}</div></div>
        <div class="legal">{{ __('procurement::ui.print.computer_generated') }}</div>
    </footer>
</main>
<script nonce="{{ Vite::cspNonce() }}">document.getElementById('print-now').addEventListener('click', function () { window.print(); });</script>
</body>
</html>
