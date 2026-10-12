<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Sign in') · DIO Billing</title>
    <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
</head>
<body>
<div class="guest">
    <h1>DIO Billing</h1>
    @if ($errors->any()) <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div> @endif
    <div class="card">@yield('content')</div>
</div>
</body>
</html>
