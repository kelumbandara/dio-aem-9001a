@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
    <h1>Invoices</h1>
    <p>
        @foreach (['' => 'All', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'void' => 'Void'] as $key => $label)
            <a class="btn {{ $status === $key ? '' : 'alt' }}" href="{{ route('invoices.index', $key === '' ? [] : ['status' => $key]) }}">{{ $label }}</a>
        @endforeach
    </p>
    <div class="card scroll"><table>
        <tr><th>Number</th><th>Customer</th><th>Period</th><th>Due</th><th class="n">Total (LKR)</th><th>Status</th></tr>
        @forelse ($invoices as $i)
            <tr><td><a href="{{ route('invoices.show', $i) }}">{{ $i->number }}</a></td><td>{{ $i->customer->name }}</td><td>{{ $i->period_start->format('j M Y') }} – {{ $i->period_end->format('j M Y') }}</td><td>{{ $i->due_date->format('j M Y') }}</td><td class="n">{{ App\Support\Billing::money((string) $i->total) }}</td>
                <td><span class="pill {{ $i->status === 'paid' ? 'ok' : ($i->status === 'void' ? 'muted' : ($i->due_date->toDateString() < $today->toDateString() ? 'bad' : 'warn')) }}">{{ $i->status }}</span></td></tr>
        @empty
            <tr><td colspan="6" class="muted">No invoices.</td></tr>
        @endforelse
    </table></div>
    {{ $invoices->links() }}
@endsection
