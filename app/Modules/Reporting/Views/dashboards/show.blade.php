@php
    $field = 'block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $askAi = app(\App\Modules\Core\Contracts\ModuleRegistry::class)->isEnabled('ai') && auth()->user()->can('ai.use') && \Illuminate\Support\Facades\Route::has('ai.chat.index');
    $q = ['period' => $d['preset'], 'compare' => $d['compare_mode'], 'from' => $d['period']->from, 'to' => $d['period']->to];
@endphp
<x-core::layouts.app :title="$d['title']">
    @include('reporting::dashboards._style')
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm rp-noprint">
        @foreach ($dashboards as $b)
            <a href="{{ route('reporting.show', $b->key()) }}" class="rounded-full px-3 py-1 {{ $b->key() === $d['key'] ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-100' }}">{{ $b->title() }}</a>
        @endforeach
    </div>
    @if ($error) <x-core::alert type="error">{{ $error }}</x-core::alert> @endif
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rp-noprint" x-data="{ custom: @js($d['preset'] === 'custom') }">
        <div><label for="period" class="mb-1 block text-xs font-medium text-slate-600">{{ __('reporting::ui.period') }}</label>
            <select id="period" name="period" class="{{ $field }}" @change="custom = $event.target.value === 'custom'">
                @foreach ($presets as $p) <option value="{{ $p }}" @selected($d['preset'] === $p)>{{ __('reporting::ui.presets.'.$p) }}</option> @endforeach
            </select></div>
        <div x-show="custom" x-cloak><label for="from" class="mb-1 block text-xs font-medium text-slate-600">{{ __('reporting::ui.from') }}</label><input id="from" type="date" name="from" value="{{ $d['period']->from }}" class="{{ $field }}"></div>
        <div x-show="custom" x-cloak><label for="to" class="mb-1 block text-xs font-medium text-slate-600">{{ __('reporting::ui.to') }}</label><input id="to" type="date" name="to" value="{{ $d['period']->to }}" class="{{ $field }}"></div>
        <div><label for="compare" class="mb-1 block text-xs font-medium text-slate-600">{{ __('reporting::ui.compare_with') }}</label>
            <select id="compare" name="compare" class="{{ $field }}">
                @foreach (['previous', 'year', 'none'] as $m) <option value="{{ $m }}" @selected($d['compare_mode'] === $m)>{{ __('reporting::ui.compare.'.$m) }}</option> @endforeach
            </select></div>
        <x-core::button type="submit" variant="secondary">{{ __('reporting::ui.apply') }}</x-core::button>
        <a class="rounded-lg px-3 py-2 text-sm text-indigo-600 hover:underline" href="{{ route('reporting.csv', [$d['key']] + $q) }}">{{ __('reporting::ui.export_csv') }}</a>
        <a class="rounded-lg px-3 py-2 text-sm text-indigo-600 hover:underline" target="_blank" href="{{ route('reporting.print', [$d['key']] + $q) }}">{{ __('reporting::ui.print') }}</a>
        @if ($askAi)
            <a class="rounded-lg px-3 py-2 text-sm text-indigo-600 hover:underline" href="{{ route('ai.chat.index', ['agent' => $d['key'] === 'stores' ? 'inventory' : $d['key'], 'q' => __('reporting::ui.ask_ai_question', ['title' => $d['title'], 'from' => $d['period']->from, 'to' => $d['period']->to, 'cfrom' => $d['compare']?->from ?? '', 'cto' => $d['compare']?->to ?? ''])]) }}">{{ __('reporting::ui.ask_ai') }}</a>
        @endif
    </form>
    @include('reporting::dashboards._body')
</x-core::layouts.app>
