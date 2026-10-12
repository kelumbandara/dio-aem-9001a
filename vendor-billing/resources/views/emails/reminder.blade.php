<p>Dear {{ $invoice->billed_to['name'] }},</p>
@if ($days < 0)
<p>Invoice <b>{{ $invoice->number }}</b> for LKR {{ App\Support\Billing::money((string) $invoice->total) }} is due on {{ $invoice->due_date->format('j F Y') }}.</p>
@elseif ($days < 7)
<p>Invoice <b>{{ $invoice->number }}</b> for LKR {{ App\Support\Billing::money((string) $invoice->total) }} was due on {{ $invoice->due_date->format('j F Y') }} and has not been paid. Your system keeps working for now, with a warning.</p>
@elseif ($days < 21)
<p>Invoice <b>{{ $invoice->number }}</b> is {{ $days }} days overdue. Your system is now <b>read-only</b>: you can look and print, but not post or change anything.</p>
@else
<p>Invoice <b>{{ $invoice->number }}</b> is {{ $days }} days overdue. Your system is <b>locked</b> until the invoice is paid. Your data is safe.</p>
@endif
<p><a href="{{ route('pay.show', $invoice->public_token) }}">View the invoice and pay online</a></p>
@if (! empty($invoice->issuer['bank_details']))
<p>Or pay by bank transfer, quoting {{ $invoice->number }}:</p>
<pre>{{ $invoice->issuer['bank_details'] }}</pre>
@endif
