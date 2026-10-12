@extends('layouts.guest')
@section('title', 'Authenticator code')
@section('content')
    <form method="post" action="{{ route('mfa.challenge.post') }}">
        @csrf
        <label for="code">Code from your authenticator app</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        <p><button type="submit">Sign in</button></p>
    </form>
@endsection
