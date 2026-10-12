@extends('layouts.app')
@section('title', 'Activity')
@section('content')
    <h1>Activity</h1>
    <div class="card scroll"><table>
        <tr><th>When</th><th>Who</th><th>What</th><th>About</th><th>Details</th></tr>
        @foreach ($entries as $e)
            <tr><td>{{ $e->created_at->setTimezone(config('billing.timezone'))->format('j M Y H:i') }}</td><td>{{ $e->user?->name ?? 'system' }}</td><td>{{ $e->action }}</td><td>{{ $e->subject_type }} {{ $e->subject_id }}</td><td class="muted">{{ $e->details ? json_encode($e->details, JSON_UNESCAPED_SLASHES) : '' }}</td></tr>
        @endforeach
    </table></div>
    {{ $entries->links() }}
@endsection
