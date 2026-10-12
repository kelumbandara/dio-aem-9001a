@props(['title' => null])
@php
    $look = app(\App\Modules\Core\Contracts\Appearance::class);
    $logo = $look->logoUrl();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($title ? $title.' · ' : '').$look->appName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>{!! $look->themeCss() !!}</style>
    @if ($logo) <link rel="icon" href="{{ $logo }}"> @endif
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <div class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-10">
        <div class="mb-6 flex items-center justify-between">
            <span class="flex min-w-0 items-center gap-3 text-xl font-semibold tracking-tight text-slate-900">
                @if ($logo) <img src="{{ $logo }}" alt="" class="h-12 max-w-40 object-contain"> @endif
                <span class="truncate">{{ $look->appName() }}</span>
            </span>
            <x-core::language-switcher />
        </div>
        @if ($look->loginMessage())
            <p class="mb-4 text-sm text-slate-600">{{ $look->loginMessage() }}</p>
        @endif
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            {{ $slot }}
        </div>
        @if ($look->poweredBy())
            <p class="mt-6 text-center text-xs text-slate-400">{{ $look->poweredBy() }}</p>
        @endif
    </div>
</body>
</html>
