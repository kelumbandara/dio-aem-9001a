@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    <h1>Dashboard</h1>
    <div class="grid">
        <div class="stat"><b>{{ $customers }}</b><span>Customers</span></div>
        <div class="stat"><b>{{ $inTrial }}</b><span>In free trial</span></div>
        <div class="stat"><b>LKR {{ App\Support\Billing::money($recurring) }}</b><span>Monthly recurring (excl. VAT)</span></div>
        <div class="stat"><b class="{{ $overdue->isNotEmpty() ? 'bad' : '' }}">LKR {{ App\Support\Billing::money($overdueTotal) }}</b><span>Overdue ({{ $overdue->count() }} invoices)</span></div>
    </div>

    @if (count($paidWithoutLicence) > 0)
        <div class="card"><h2 style="margin-top:0" class="bad">Paid, but no licence sent</h2>
            <p class="muted">Usually the installation ID is missing, or the signing key is not set on this server. Fix it, then press “Issue licence” on the customer.</p>
            @foreach ($paidWithoutLicence as $row)
                <div><a href="{{ route('customers.show', $row['customer']) }}">{{ $row['customer']->code }} · {{ $row['customer']->name }}</a> — paid through {{ $row['through']->format('j M Y') }}@if (blank($row['customer']->installation_id)) <span class="pill warn">no installation ID</span>@endif</div>
            @endforeach
        </div>
    @endif

    <div class="card"><h2 style="margin-top:0">Overdue invoices</h2>
        @forelse ($overdue as $invoice)
            <div><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a> · {{ $invoice->customer->name }} · due {{ $invoice->due_date->format('j M Y') }} ({{ (int) App\Support\Billing::day($invoice->due_date->toDateString())->diffInDays($today) }} days) · LKR {{ App\Support\Billing::money($invoice->balance()) }}</div>
        @empty
            <span class="muted">Nothing overdue.</span>
        @endforelse
    </div>

    <div class="card"><h2 style="margin-top:0">Trials ending in the next 14 days</h2>
        @forelse ($trialsEnding as $s)
            <div><a href="{{ route('customers.show', $s->customer) }}">{{ $s->customer->name }}</a> — trial ends {{ $s->trial_ends_on->format('j M Y') }}@if (blank($s->customer->installation_id)) <span class="pill warn">no installation ID</span>@endif</div>
        @empty
            <span class="muted">None.</span>
        @endforelse
    </div>

    <div class="card"><h2 style="margin-top:0">Latest licences issued</h2>
        @forelse ($recent as $l)
            <div>{{ $l->serial }} · {{ $l->customer->name }} · {{ $l->valid_from->format('j M Y') }} → {{ $l->valid_until->format('j M Y') }} @if ($l->kind === 'suspension')<span class="pill bad">suspension</span>@endif</div>
        @empty
            <span class="muted">None yet.</span>
        @endforelse
    </div>
    @if ($needsInstallation > 0) <p class="muted">{{ $needsInstallation }} customer(s) have no installation ID yet.</p> @endif
@endsection
