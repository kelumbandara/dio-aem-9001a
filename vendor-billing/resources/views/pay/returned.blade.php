<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Thank you</title>
    <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
</head>
<body>
<div class="guest">
    <h1>Thank you</h1>
    <div class="card">
        @if ($invoice->status === 'paid')
            <p class="ok"><b>Payment received for {{ $invoice->number }}.</b> Your licence will arrive by e-mail shortly.</p>
        @else
            <p>We are waiting for PayHere to confirm your payment for {{ $invoice->number }}. This normally takes a minute; your licence is e-mailed as soon as it arrives. You can refresh this page.</p>
        @endif
        <p><a href="{{ route('invoice.public', $invoice->public_token) }}">View the tax invoice</a></p>
    </div>
</div>
</body>
</html>
