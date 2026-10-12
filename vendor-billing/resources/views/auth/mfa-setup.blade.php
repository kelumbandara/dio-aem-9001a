@extends('layouts.guest')
@section('title', 'Link your authenticator')
@section('content')
    <p>Scan this code with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy), then type the 6-digit code it shows.</p>
    <div style="background:#fff;display:inline-block;padding:6px">{!! $qr_svg !!}</div>
    <p class="muted">Cannot scan? Enter this key by hand: <span class="tokenbox">{{ $secret }}</span></p>
    <form method="post" action="{{ route('mfa.setup.post') }}">
        @csrf
        <label for="code">Code from the app</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        <p><button type="submit">Link and sign in</button></p>
    </form>
@endsection
