@extends('layouts.app')
@section('title', 'Customers')
@section('content')
    <h1>Customers <a class="btn" style="float:right" href="{{ route('customers.create') }}">New customer</a></h1>
    <form method="get" class="inline" style="margin-bottom:12px">
        <div><input name="q" value="{{ $q }}" placeholder="Search name, code or e-mail"></div>
        <button class="alt" type="submit">Search</button>
    </form>
    <div class="card scroll">
        <table>
            <tr><th>Code</th><th>Name</th><th>E-mail</th><th>Status</th><th>Trial ends</th><th>Installation</th></tr>
            @forelse ($customers as $c)
                <tr>
                    <td><a href="{{ route('customers.show', $c) }}">{{ $c->code }}</a></td>
                    <td>{{ $c->name }}</td><td>{{ $c->email }}</td>
                    <td>@if ($c->subscription?->status === 'suspended')<span class="pill bad">suspended</span>@elseif ($c->subscription && $c->subscription->trial_ends_on->toDateString() >= $today->toDateString())<span class="pill warn">trial</span>@else<span class="pill ok">active</span>@endif</td>
                    <td>{{ $c->subscription?->trial_ends_on->format('j M Y') }}</td>
                    <td>@if ($c->installation_id)<span class="ok">set</span>@else<span class="warn">missing</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No customers yet.</td></tr>
            @endforelse
        </table>
    </div>
    {{ $customers->links() }}
@endsection
