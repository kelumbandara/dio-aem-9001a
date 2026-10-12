@props(['title' => null])
@php
    $look = app(\App\Modules\Core\Contracts\Appearance::class);
    $logo = $look->logoUrl();
    $menu = app(\App\Modules\Core\Contracts\ModuleRegistry::class)->menuFor(auth()->user());
    $pageTitle = ($title ? $title.' · ' : '').$look->appName();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>{!! $look->themeCss() !!}</style>
    @if ($logo) <link rel="icon" href="{{ $logo }}"> @endif
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased" x-data="{ nav: false }" @keydown.escape.window="nav = false">
    <div class="flex min-h-screen">
        {{-- Sidebar: off-canvas below lg, fixed column from lg up --}}
        <div class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" x-show="nav" x-transition.opacity @click="nav = false" x-cloak></div>
        <aside class="fixed inset-y-0 start-0 z-40 flex w-64 -translate-x-full flex-col border-e border-(--sidebar-border) bg-(--sidebar-bg) text-(--sidebar-text) transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 rtl:translate-x-full lg:rtl:translate-x-0"
               :class="{ 'translate-x-0 rtl:translate-x-0': nav }">
            <div class="flex h-16 items-center gap-3 px-5 text-lg font-semibold tracking-tight text-(--sidebar-title)">
                @if ($logo)
                    <img src="{{ $logo }}" alt="" class="h-9 max-w-28 shrink-0 rounded-md bg-white object-contain p-0.5">
                @endif
                <span class="min-w-0 truncate">{{ $look->appName() }}</span>
            </div>
            @php
                $order = ['main', 'approvals', 'sales', 'purchasing', 'inventory', 'manufacturing', 'finance', 'masterdata', 'admin'];
                $sections = collect($menu)->groupBy('group')->sortBy(fn ($items, $g) => (($i = array_search($g, $order, true)) === false ? 99 : $i));
                $activeGroup = optional(collect($menu)->first(fn ($i) => request()->routeIs($i->route)))->group;
                $labels = collect($menu)->map(fn ($i) => mb_strtolower(__($i->labelKey)))->values();
            @endphp
            <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 py-2" aria-label="Main"
                 x-data="{
                     q: '',
                     active: @js($activeGroup),
                     open: (() => { try { return JSON.parse(localStorage.getItem('nav.open') || '{}'); } catch (e) { return {}; } })(),
                     init() { if (this.active) { this.open[this.active] = true; } },
                     isOpen(g) { return this.q !== '' || this.open[g] === true; },
                     toggle(g) { this.open[g] = ! this.isOpen(g); try { localStorage.setItem('nav.open', JSON.stringify(this.open)); } catch (e) {} },
                     hit(label) { return this.q === '' || label.includes(this.q.toLowerCase().trim()); },
                     none() { return this.q !== '' && ! @js($labels).some(l => this.hit(l)); }
                 }">
                <div class="relative px-1 pb-2">
                    <label for="nav-search" class="sr-only">{{ __('core::ui.menu_groups.find') }}</label>
                    <x-core::icon name="search" class="pointer-events-none absolute start-4 top-2.5 h-4 w-4 opacity-60" />
                    <input id="nav-search" type="search" x-model="q" autocomplete="off" placeholder="{{ __('core::ui.menu_groups.find') }}"
                           class="w-full rounded-lg border-0 bg-(--sidebar-hover) py-2 ps-9 pe-3 text-sm text-(--sidebar-text) placeholder:text-(--sidebar-text) placeholder:opacity-60 focus:ring-2 focus:ring-(--sidebar-active-text)">
                </div>
                @foreach ($sections as $group => $items)
                    @php $items = $items->sortBy('order')->values(); $groupLabels = $items->map(fn ($i) => mb_strtolower(__($i->labelKey)))->values(); @endphp
                    <div x-show="q === '' || @js($groupLabels).some(l => hit(l))">
                        @if ($group !== 'main')
                            <button type="button" @click="toggle(@js($group))" :aria-expanded="isOpen(@js($group)).toString()"
                                    class="mt-2 flex w-full items-center justify-between rounded-lg px-3 py-1.5 text-xs font-semibold uppercase tracking-wider opacity-80 hover:bg-(--sidebar-hover) hover:opacity-100">
                                <span>{{ __('core::ui.menu_groups.'.$group) }}</span>
                                <x-core::icon name="chevron-down" class="h-4 w-4 transition-transform" ::class="isOpen(@js($group)) ? '' : '-rotate-90 rtl:rotate-90'" />
                            </button>
                        @endif
                        <div class="space-y-1" @if ($group !== 'main') x-show="isOpen(@js($group))" @endif>
                            @foreach ($items as $item)
                                @php $active = request()->routeIs($item->route); @endphp
                                <a href="{{ route($item->route) }}" x-show="hit(@js(mb_strtolower(__($item->labelKey))))"
                                   @class([
                                       'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium',
                                       'bg-(--sidebar-active-bg) text-(--sidebar-active-text)' => $active,
                                       'text-(--sidebar-text) hover:bg-(--sidebar-hover)' => ! $active,
                                   ])
                                   @if ($active) aria-current="page" @endif>
                                    <x-core::icon :name="$item->icon" class="h-5 w-5 shrink-0" />
                                    <span>{{ __($item->labelKey) }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <p x-show="none()" x-cloak class="px-3 py-2 text-sm opacity-70">{{ __('core::ui.menu_groups.none') }}</p>
            </nav>
            @if ($look->poweredBy())
                <div class="px-5 py-3 text-xs text-(--sidebar-text) opacity-70">{{ $look->poweredBy() }}</div>
            @endif
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
                <button type="button" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="nav = true" aria-label="{{ __('core::ui.common.open_menu') }}">
                    <x-core::icon name="menu" class="h-6 w-6" />
                </button>
                <h1 class="min-w-0 flex-1 truncate text-lg font-semibold text-slate-900">{{ $title }}</h1>
                @php $unread = auth()->user()->unreadNotifications()->count(); @endphp
                <a href="{{ route('notifications.index') }}" class="relative rounded-lg p-2 text-slate-600 hover:bg-slate-100" aria-label="{{ __('core::ui.notifications.title') }}">
                    <x-core::icon name="bell" class="h-6 w-6" />
                    @if ($unread > 0)
                        <span class="absolute -end-0.5 -top-0.5 min-w-5 rounded-full bg-red-600 px-1 text-center text-[11px] font-semibold leading-5 text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
                    @endif
                </a>
                <x-core::language-switcher />
                <div class="hidden text-sm text-slate-600 sm:block">{{ auth()->user()->name }}</div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-core::button type="submit" variant="secondary" size="sm">{{ __('core::ui.common.sign_out') }}</x-core::button>
                </form>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6">
                <x-core::licence-banner />
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
