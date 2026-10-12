<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Pay {{ $invoice->number }}</title>
    <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
</head>
<body>
<div class="guest">
    <h1>Invoice {{ $invoice->number }}</h1>
    <div class="card">
        <p>{{ $invoice->billed_to['name'] }}<br>Subscription {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}</p>
        <p><b>Total LKR {{ App\Support\Billing::money((string) $invoice->total) }}</b> (including VAT {{ App\Support\Billing::money((string) $invoice->vat) }})</p>
        @if ($invoice->status === 'paid')
            <p class="ok"><b>This invoice is paid. Thank you.</b></p>
        @elseif ($invoice->status === 'void')
            <p class="muted">This invoice has been cancelled.</p>
        @elseif ($fields !== null)
            <p>Balance to pay: <b>LKR {{ App\Support\Billing::money($balance) }}</b></p>
            <form method="post" action="{{ $url }}">
                @foreach ($fields as $name => $value) <input type="hidden" name="{{ $name }}" value="{{ $value }}"> @endforeach
                <button type="submit">Pay online with PayHere</button>
            </form>
        @else
            <p class="muted">Online payment is not available right now. Please pay by bank transfer using the details on the invoice.</p>
        @endif
        <p><a href="{{ route('invoice.public', $invoice->public_token) }}">View the tax invoice</a></p>
    </div>
</div>
</body>
</html>
