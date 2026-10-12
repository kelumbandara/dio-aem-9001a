<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $d['title'] }} · {{ app(\App\Modules\Core\Contracts\Appearance::class)->appName() }}</title>
    @vite(['resources/css/app.css'])
    @include('reporting::dashboards._style')
</head>
<body class="bg-white p-6 text-slate-800">
    <h1 class="mb-1 text-xl font-semibold">{{ $d['title'] }}</h1>
    <p class="mb-4 text-xs text-slate-500">{{ __('reporting::ui.printed', ['at' => now(config('core.display_timezone'))->format('Y-m-d H:i')]) }}</p>
    @include('reporting::dashboards._body')
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
