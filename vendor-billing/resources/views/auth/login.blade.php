@extends('layouts.guest')
@section('content')
    <form method="post" action="{{ route('login.post') }}">
        @csrf
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <p><button type="submit">Continue</button></p>
    </form>
@endsection
