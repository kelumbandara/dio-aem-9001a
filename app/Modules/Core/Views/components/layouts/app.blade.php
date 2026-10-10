@props(['title' => null])
@php
    $menu = app(\App\Modules\Core\Contracts\ModuleRegistry::class)->menuFor(auth()->user());
    $pageTitle = ($title ? $title.' · ' : '').__('core::ui.app_name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased" x-data="{ nav: false }" @keydown.escape.window="nav = false">
    <div class="flex min-h-screen">
        {{-- Sidebar: off-canvas below lg, fixed column from lg up --}}
        <div class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" x-show="nav" x-transition.opacity @click="nav = false" x-cloak></div>
        <aside class="fixed inset-y-0 start-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 text-slate-200 transition-transform lg:static lg:translate-x-0 rtl:translate-x-full lg:rtl:translate-x-0"
               :class="{ 'translate-x-0 rtl:translate-x-0': nav }">
            <div class="flex h-16 items-center px-5 text-lg font-semibold tracking-tight text-white">{{ __('core::ui.app_name') }}</div>
            <nav class="flex-1 space-y-1 px-3 py-2" aria-label="Main">
                @foreach ($menu as $item)
                    @php $active = request()->routeIs($item->route); @endphp
                    <a href="{{ route($item->route) }}"
                       @class([
                           'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium',
                           'bg-slate-800 text-white' => $active,
                           'text-slate-300 hover:bg-slate-800/60 hover:text-white' => ! $active,
                       ])
                       @if ($active) aria-current="page" @endif>
                        <x-core::icon :name="$item->icon" class="h-5 w-5 shrink-0" />
                        <span>{{ __($item->labelKey) }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
                <button type="button" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="nav = true" aria-label="{{ __('core::ui.common.open_menu') }}">
                    <x-core::icon name="menu" class="h-6 w-6" />
                </button>
                <h1 class="min-w-0 flex-1 truncate text-lg font-semibold text-slate-900">{{ $title }}</h1>
                <x-core::language-switcher />
                <div class="hidden text-sm text-slate-600 sm:block">{{ auth()->user()->name }}</div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-core::button type="submit" variant="secondary" size="sm">{{ __('core::ui.common.sign_out') }}</x-core::button>
                </form>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6">
                @if (session('status'))
                    <x-core::alert type="success" class="mb-4">{{ session('status') }}</x-core::alert>
                @endif
                @if ($errors->any())
                    <x-core::alert type="error" class="mb-4">{{ $errors->first() }}</x-core::alert>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
