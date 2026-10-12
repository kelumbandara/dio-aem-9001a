<p>Dear {{ $invoice->billed_to['name'] }},</p>
<p>Your subscription invoice <b>{{ $invoice->number }}</b> for {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }} is ready: <b>LKR {{ App\Support\Billing::money((string) $invoice->total) }}</b> including VAT, due {{ $invoice->due_date->format('j F Y') }}.</p>
<p><a href="{{ route('invoice.public', $invoice->public_token) }}">View the tax invoice</a> · <a href="{{ route('pay.show', $invoice->public_token) }}">Pay online</a></p>
@if (! empty($invoice->issuer['bank_details']))
<p>Or pay by bank transfer, quoting {{ $invoice->number }}:</p>
<pre>{{ $invoice->issuer['bank_details'] }}</pre>
@endif
