@extends('layouts.app')
@section('title', $customer->name)
@section('content')
    @php($sub = $customer->subscription)
    <h1>{{ $customer->code }} · {{ $customer->name }} <a class="btn alt" style="float:right" href="{{ route('customers.edit', $customer) }}">Edit</a></h1>

    @if (session('new_token'))
        <div class="card"><b>New API token — copy it now, it is shown only once:</b>
            <div class="tokenbox">{{ session('new_token') }}</div>
            <p class="muted">Put it, with this server's address, in the installation's environment (<code>VENDOR_BILLING_URL</code>, <code>VENDOR_BILLING_TOKEN</code>) so it can fetch its own renewals.</p></div>
    @endif

    <div class="grid">
        <div class="stat"><b>{{ $sub?->status }}</b><span>Subscription</span></div>
        <div class="stat"><b>LKR {{ App\Support\Billing::money((string) $sub?->monthly_price) }}</b><span>Per month, excl. VAT ({{ $sub?->plan }})</span></div>
        <div class="stat"><b>{{ $sub?->trial_ends_on->format('j M Y') }}</b><span>Free trial ends</span></div>
        <div class="stat"><b>{{ $paidThrough?->format('j M Y') ?? '—' }}</b><span>Paid through</span></div>
    </div>

    <div class="card">
        <div class="row">
            <div><b>E-mail</b><br>{{ $customer->email }}</div>
            <div><b>Contact</b><br>{{ $customer->contact_name }} {{ $customer->phone }}</div>
            <div><b>VAT / TIN</b><br>{{ $customer->vat_number ?: '—' }}</div>
            <div><b>Installation ID</b><br>@if ($customer->installation_id){{ $customer->installation_id }}@else<span class="warn">missing — no licence can be issued</span>@endif</div>
        </div>
        <div class="inline" style="margin-top:12px">
            <form method="post" action="{{ route('customers.licence', $customer) }}">@csrf <button class="alt" type="submit">Issue licence for what is paid</button></form>
            @if (auth()->user()->isOwner())
                <form method="post" action="{{ route('customers.token', $customer) }}" onsubmit="return confirm('Make a new token? The old one stops working.')">@csrf <button class="alt" type="submit">New API token</button></form>
                @if ($sub?->status === 'suspended')
                    <form method="post" action="{{ route('customers.resume', $customer) }}">@csrf <button type="submit">Resume</button></form>
                @else
                    <form method="post" action="{{ route('customers.suspend', $customer) }}" onsubmit="return confirm('Shut this installation out now?')">@csrf <button class="danger" type="submit">Suspend</button></form>
                @endif
            @endif
        </div>
    </div>

    <h2>Invoices</h2>
    <div class="card scroll"><table>
        <tr><th>Number</th><th>Period</th><th>Due</th><th class="n">Total</th><th>Status</th></tr>
        @forelse ($invoices as $i)
            <tr><td><a href="{{ route('invoices.show', $i) }}">{{ $i->number }}</a></td><td>{{ $i->period_start->format('j M Y') }} – {{ $i->period_end->format('j M Y') }}</td><td>{{ $i->due_date->format('j M Y') }}</td><td class="n">{{ App\Support\Billing::money((string) $i->total) }}</td>
                <td><span class="pill {{ $i->status === 'paid' ? 'ok' : ($i->status === 'void' ? 'muted' : ($i->due_date->toDateString() < $today->toDateString() ? 'bad' : 'warn')) }}">{{ $i->status }}</span></td></tr>
        @empty
            <tr><td colspan="5" class="muted">No invoice yet — the first is issued a few days before the trial ends.</td></tr>
        @endforelse
    </table></div>

    <h2>Licences issued</h2>
    <div class="card scroll"><table>
        <tr><th>Serial</th><th>Kind</th><th>Valid</th><th>Issued</th><th>E-mailed</th></tr>
        @forelse ($licences as $l)
            <tr><td>{{ $l->serial }}</td><td>{{ $l->kind }}</td><td>{{ $l->valid_from->format('j M Y') }} → {{ $l->valid_until->format('j M Y') }}</td><td>{{ $l->issued_at->setTimezone(config('billing.timezone'))->format('j M Y H:i') }}</td><td>{{ $l->emailed_at ? 'yes' : 'no' }}</td></tr>
            <tr><td colspan="5"><details><summary>Show licence text</summary><div class="tokenbox">{{ $l->token }}</div></details></td></tr>
        @empty
            <tr><td colspan="5" class="muted">None yet.</td></tr>
        @endforelse
    </table></div>
@endsection
