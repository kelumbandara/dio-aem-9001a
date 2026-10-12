<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Tax invoice {{ $invoice->number }}</title>
    <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
</head>
<body style="background:#f3f4f6">
@php($from = $invoice->issuer)
@php($to = $invoice->billed_to)
<div class="doc">
    <div class="head">
        <div>
            <h1>TAX INVOICE</h1>
            <div>No. <b>{{ $invoice->number }}</b></div>
            <div>Date: {{ $invoice->issue_date->format('j F Y') }}</div>
            <div>Due: {{ $invoice->due_date->format('j F Y') }}</div>
        </div>
        <div style="text-align:right">
            <b>{{ $from['name'] }}</b>
            <div style="white-space:pre-line">{{ $from['address'] }}</div>
            @if (! empty($from['vat_number'])) <div>VAT No: {{ $from['vat_number'] }}</div> @endif
            <div>{{ $from['email'] }} {{ $from['phone'] }}</div>
        </div>
    </div>

    <h2>Billed to</h2>
    <div><b>{{ $to['name'] }}</b> ({{ $to['code'] }})</div>
    <div style="white-space:pre-line">{{ $to['address'] }}</div>
    @if (! empty($to['vat_number'])) <div>VAT / TIN: {{ $to['vat_number'] }}</div> @endif

    <table style="margin-top:24px">
        <tr><th>Description</th><th class="n">Amount (LKR)</th></tr>
        <tr><td>{{ $invoice->subscription->plan }} subscription<br><span style="color:#555">{{ $invoice->period_start->format('j M Y') }} to {{ $invoice->period_end->format('j M Y') }}</span></td><td class="n">{{ App\Support\Billing::money((string) $invoice->net) }}</td></tr>
    </table>
    <table class="totals">
        <tr><td>Subtotal</td><td class="n">{{ App\Support\Billing::money((string) $invoice->net) }}</td></tr>
        <tr><td>VAT {{ rtrim(rtrim((string) $invoice->vat_rate, '0'), '.') }}%</td><td class="n">{{ App\Support\Billing::money((string) $invoice->vat) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="n">LKR {{ App\Support\Billing::money((string) $invoice->total) }}</td></tr>
    </table>

    @if ($invoice->status === 'paid') <p><span class="stamp">PAID</span> {{ $invoice->paid_at?->setTimezone(config('billing.timezone'))->format('j F Y') }}</p>
    @elseif ($invoice->status === 'void') <p><span class="stamp" style="border-color:#b91c1c;color:#b91c1c">VOID</span></p>
    @else
        @if (! empty($from['bank_details']))
            <h2>Pay by bank transfer</h2>
            <div style="white-space:pre-line">{{ $from['bank_details'] }}</div>
            <div>Use <b>{{ $invoice->number }}</b> as the payment reference.</div>
        @endif
        @isset($payUrl) <p class="noprint"><a class="btn" href="{{ $payUrl }}">Pay online by card</a></p> @endisset
    @endif
    @if (! empty($from['footer'])) <p style="color:#555;margin-top:30px">{{ $from['footer'] }}</p> @endif
    <p class="noprint"><button class="alt" onclick="window.print()">Print</button></p>
</div>
</body>
</html>
