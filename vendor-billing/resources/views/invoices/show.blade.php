@extends('layouts.app')
@section('title', $invoice->number)
@section('content')
    <h1>{{ $invoice->number }} <span class="pill {{ $invoice->status === 'paid' ? 'ok' : ($invoice->status === 'void' ? 'muted' : 'warn') }}">{{ $invoice->status }}</span>
        <a class="btn alt" style="float:right" href="{{ route('invoices.print', $invoice) }}" target="_blank">Print / view</a></h1>
    <div class="card">
        <div class="row">
            <div><b>Customer</b><br><a href="{{ route('customers.show', $invoice->customer) }}">{{ $invoice->customer->name }}</a></div>
            <div><b>Period</b><br>{{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}</div>
            <div><b>Due</b><br>{{ $invoice->due_date->format('j M Y') }}</div>
            <div><b>Total</b><br>LKR {{ App\Support\Billing::money((string) $invoice->total) }} <span class="muted">(net {{ App\Support\Billing::money((string) $invoice->net) }} + VAT {{ App\Support\Billing::money((string) $invoice->vat) }})</span></div>
            <div><b>Received</b><br>LKR {{ App\Support\Billing::money($paid) }}</div>
            <div><b>Balance</b><br>LKR {{ App\Support\Billing::money($balance) }}</div>
        </div>
        <p class="muted">Customer's invoice and payment link: <a href="{{ route('pay.show', $invoice->public_token) }}">{{ route('pay.show', $invoice->public_token) }}</a></p>
    </div>

    <h2>Payments</h2>
    <div class="card scroll"><table>
        <tr><th>Received</th><th>Method</th><th class="n">Amount</th><th>Reference</th><th>By</th><th></th></tr>
        @forelse ($invoice->payments as $p)
            <tr>
                <td>{{ $p->received_on->format('j M Y') }}</td><td>{{ str_replace('_', ' ', $p->method) }}</td><td class="n">{{ App\Support\Billing::money((string) $p->amount) }}</td><td>{{ $p->reference }}</td><td>{{ $p->enteredBy?->name ?? 'PayHere' }}</td>
                <td>@if ($p->reversed_at)<span class="pill bad">reversed: {{ $p->reversal_reason }}</span>@elseif (auth()->user()->isOwner())
                    <form method="post" action="{{ route('invoices.reverse', [$invoice, $p]) }}" class="inline">@csrf <div><input name="reason" placeholder="Reason" required></div><button class="alt" type="submit">Reverse</button></form>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No payments.</td></tr>
        @endforelse
    </table></div>

    @if ($invoice->status === 'unpaid')
        <h2>Record a bank transfer</h2>
        <form class="card inline" method="post" action="{{ route('invoices.pay', $invoice) }}">
            @csrf
            <div><label for="amount">Amount (LKR)</label><input id="amount" name="amount" value="{{ old('amount', $balance) }}" inputmode="decimal" required></div>
            <div><label for="received_on">Received on</label><input id="received_on" type="date" name="received_on" value="{{ old('received_on', $today->toDateString()) }}" required></div>
            <div><label for="reference">Bank reference</label><input id="reference" name="reference" value="{{ old('reference') }}"></div>
            <button type="submit">Record payment</button>
        </form>
        @if (auth()->user()->isOwner())
            <h2>Void this invoice</h2>
            <form class="card inline" method="post" action="{{ route('invoices.void', $invoice) }}" onsubmit="return confirm('Void this invoice? That month will be treated as waived.')">
                @csrf <div><label for="reason">Reason</label><input id="reason" name="reason" required></div><button class="danger" type="submit">Void</button>
            </form>
        @endif
    @endif
@endsection
