<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Billing') · DIO Billing</title>
    <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
</head>
<body>
<header class="top">
    <strong>DIO Billing</strong>
    <nav>
        <a href="{{ route('dashboard') }}" @class(['on' => request()->routeIs('dashboard')])>Dashboard</a>
        <a href="{{ route('customers.index') }}" @class(['on' => request()->routeIs('customers.*')])>Customers</a>
        <a href="{{ route('invoices.index') }}" @class(['on' => request()->routeIs('invoices.*')])>Invoices</a>
        @if (auth()->user()->isOwner())
            <a href="{{ route('settings.edit') }}" @class(['on' => request()->routeIs('settings.*')])>Settings</a>
            <a href="{{ route('activity') }}" @class(['on' => request()->routeIs('activity')])>Activity</a>
        @endif
    </nav>
    <form method="post" action="{{ route('logout') }}">@csrf <button class="alt" type="submit">Sign out ({{ auth()->user()->name }})</button></form>
</header>
<main>
    @if (session('status')) <div class="flash">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="errors">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div> @endif
    @yield('content')
</main>
</body>
</html>
