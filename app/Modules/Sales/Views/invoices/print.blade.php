@php
    // Decimal strings only, never floats: thousands separators, 2 places, half-up.
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
    <title>{{ __('sales::ui.print.title') }} {{ $doc['document_no'] }}</title>
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #3730a3; --soft: #f1f5f9; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; color: var(--ink); font: 13px/1.45 var(--font, system-ui, sans-serif); }
        .bar { display: flex; gap: 8px; justify-content: center; padding: 12px; }
        .bar a, .bar button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; }
        .bar button { background: var(--accent); color: #fff; border-color: var(--accent); }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto 24px; background: #fff; padding: 14mm 14mm 12mm; position: relative; box-shadow: 0 2px 16px rgba(15,23,42,.15); border-top: 6px solid var(--accent); }
        header { display: flex; justify-content: space-between; gap: 24px; }
        .brand h1 { margin: 0; font-size: 20px; letter-spacing: .2px; }
        .brand p, .meta p { margin: 2px 0; color: var(--muted); }
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
        .sum .base { color: var(--muted); font-size: 11px; }
        .pay { margin-top: 18px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .pay table { border-collapse: collapse; width: 100%; }
        .pay td { padding: 1px 0; } .pay td:first-child { color: var(--muted); width: 38%; }
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
    <a href="{{ route('sales.invoices.show', $doc['id']) }}">&larr; {{ __('sales::ui.common.back') }}</a>
    <button type="button" onclick="window.print()">{{ __('sales::ui.print.print') }}</button>
</div>

<main class="sheet">
    <header>
        <div class="brand">
            <x-core::branding.document-logo />
            <h1>{{ $name }}</h1>
            @if ($c['trade_name'] && $c['trade_name'] !== $c['legal_name'])<p>{{ $c['legal_name'] }}</p>@endif
            @foreach ($c['address'] as $line)<p>{{ $line }}</p>@endforeach
            @if ($c['phone'] || $c['email'])<p>{{ implode(' · ', array_filter([$c['phone'], $c['email']])) }}</p>@endif
            @if ($c['website'])<p>{{ $c['website'] }}</p>@endif
            @if ($c['tax_id'])<p class="tin">{{ __('sales::ui.print.tax_id') }}: {{ $c['tax_id'] }}</p>@endif
            @if ($c['registration_no'])<p>{{ __('sales::ui.print.reg_no') }}: {{ $c['registration_no'] }}</p>@endif
        </div>
        <div class="title">
            <h2>{{ __('sales::ui.print.title') }}</h2>
            <table>
                <tr><td>{{ __('sales::ui.print.invoice_no') }}</td><td><strong>{{ $doc['document_no'] }}</strong></td></tr>
                <tr><td>{{ __('sales::ui.print.date') }}</td><td>{{ $doc['invoice_date'] }}</td></tr>
                <tr><td>{{ __('sales::ui.invoice.due') }}</td><td>{{ $doc['due_date'] }}</td></tr>
                <tr><td>{{ __('sales::ui.print.order_no') }}</td><td>{{ $doc['order'] }}</td></tr>
                @if ($doc['customer_ref'])<tr><td>{{ __('sales::ui.form.customer_ref') }}</td><td>{{ $doc['customer_ref'] }}</td></tr>@endif
            </table>
        </div>
    </header>

    <section class="parties">
        <div class="box">
            <h3>{{ __('sales::ui.print.bill_to') }}</h3>
            <strong>{{ $doc['bill_to']['name'] }}</strong>
            @foreach ($doc['bill_to']['address'] as $line)<div>{{ $line }}</div>@endforeach
            @if ($doc['bill_to']['tax_id'])<div class="code">{{ __('sales::ui.print.customer_tax_id') }}: {{ $doc['bill_to']['tax_id'] }}</div>@endif
            <div class="code">{{ $doc['bill_to']['code'] }}</div>
        </div>
        <div class="box">
            <h3>{{ __('sales::ui.print.terms') }}</h3>
            <div>{{ $doc['payment_terms_days'] > 0 ? __('sales::ui.print.net_days', ['days' => $doc['payment_terms_days']]) : __('sales::ui.print.due_on_receipt') }}</div>
            <div class="code">{{ __('sales::ui.print.currency') }}: LKR</div>
        </div>
    </section>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:24px">#</th><th>{{ __('sales::ui.print.description') }}</th>
                <th class="r">{{ __('sales::ui.common.qty') }}</th><th class="r">{{ $incl ? __('sales::ui.common.unit_price_incl') : __('sales::ui.common.unit_price') }}</th>
                <th class="r">{{ __('sales::ui.common.discount') }}</th><th class="r">{{ __('sales::ui.common.vat') }}</th><th class="r">{{ $incl ? __('sales::ui.print.line_total_incl') : __('sales::ui.common.amount') }}</th>
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
                    <td class="r">{{ $l['tax_code'] === null ? '' : ((float) $l['vat_rate'] > 0 ? $q($l['vat_rate']).'%' : $l['tax_code']) }}</td>
                    <td class="r">{{ $m($incl ? bcadd($l['net_amount'], $l['vat_amount'], 4) : $l['net_amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="notes">@if ($doc['notes'])<strong>{{ __('sales::ui.form.notes') }}</strong><br>{{ $doc['notes'] }}@endif</div>
        <table class="sum">
            <tr><td>{{ __('sales::ui.common.subtotal') }}</td><td class="r">{{ $m($doc['totals']['net']) }}</td></tr>
            @foreach ($doc['taxes'] as $t)
                <tr>
                    <td>{{ $t['name'] ?: $t['code'] }} ({{ $q($t['rate']) }}%@if ($incl && $t['kind'] === 'vat') {{ __('sales::ui.print.included') }}@endif)@if ($t['kind'] !== 'vat')<div class="base">{{ __('sales::ui.print.on_liable', ['base' => $m($t['base_amount'])]) }}</div>@endif</td>
                    <td class="r">{{ $m($t['amount']) }}</td>
                </tr>
            @endforeach
            <tr class="grand"><td>{{ __('sales::ui.print.total_due') }} (LKR)</td><td class="r">{{ $m($doc['totals']['gross']) }}</td></tr>
        </table>
    </div>

    <section class="pay">
        @if ($doc['banks'])
            <div class="box">
                <h3>{{ __('sales::ui.print.pay_to') }}</h3>
                @foreach ($doc['banks'] as $b)
                    <table @if (! $loop->first) style="margin-top:6px" @endif>
                        <tr><td>{{ __('sales::ui.print.bank') }}</td><td>{{ $b['bank_name'] ?: $b['name'] }}</td></tr>
                        @if ($b['account_no'])<tr><td>{{ __('sales::ui.print.account_no') }}</td><td><strong>{{ $b['account_no'] }}</strong></td></tr>@endif
                        <tr><td>{{ __('sales::ui.print.account_name') }}</td><td>{{ $c['legal_name'] }}</td></tr>
                    </table>
                @endforeach
                <div class="code" style="margin-top:6px">{{ __('sales::ui.print.reference_hint', ['no' => $doc['document_no']]) }}</div>
            </div>
        @endif
    </section>

    <footer>
        <x-core::branding.document-footer />
        <div class="sign"><div>{{ __('sales::ui.print.prepared_by') }}</div><div>{{ __('sales::ui.print.received_by') }}</div></div>
        <div class="legal">{{ __('sales::ui.print.computer_generated') }}</div>
    </footer>
</main>
</body>
</html>
