@php
    use App\Modules\Reporting\Support\Format;
    use App\Modules\Reporting\Support\SvgChart;
    $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '■'];
@endphp
<p class="mb-4 text-sm text-slate-600">
    {{ __('reporting::ui.showing', ['from' => $d['period']->from, 'to' => $d['period']->to]) }}
    @if ($d['compare']) · {{ __('reporting::ui.compared', ['from' => $d['compare']->from, 'to' => $d['compare']->to]) }} @endif
</p>

@if (count($d['kpis']))
<section aria-label="{{ __('reporting::ui.kpis') }}" class="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($d['kpis'] as $k)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-kpi="{{ $k['key'] }}">
            <p class="text-sm font-medium text-slate-500">
                @if (! empty($k['link']['url'])) <a class="hover:underline" href="{{ $k['link']['url'] }}">{{ $k['label'] }}</a> @else {{ $k['label'] }} @endif
            </p>
            <p class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 tabular-nums">{{ Format::value($k['unit'], $k['value']) }}</p>
            @if ($k['trend'])
                <p class="mt-1 text-xs {{ $k['good'] === true ? 'text-emerald-700' : ($k['good'] === false ? 'text-rose-700' : 'text-slate-500') }}">
                    <span aria-hidden="true">{{ $arrow[$k['trend']] }}</span>
                    @if ($k['trend'] === 'flat') {{ __('reporting::ui.no_change') }}
                    @elseif ($k['unit'] === 'pct') {{ ($k['trend'] === 'up' ? '+' : '') }}{{ $k['change'] }} {{ __('reporting::ui.points') }}
                    @elseif ($k['change_pct'] !== null) {{ ($k['trend'] === 'up' ? '+' : '') }}{{ $k['change_pct'] }} %
                    @else {{ __('reporting::ui.was_zero') }} @endif
                    <span class="text-slate-500">· {{ __('reporting::ui.was') }} {{ Format::value($k['unit'], $k['previous']) }}</span>
                </p>
            @endif
            @if ($k['hint']) <p class="mt-1 text-xs text-slate-500">{{ $k['hint'] }}</p> @endif
        </div>
    @endforeach
</section>
@endif

<section class="mb-8 grid gap-6 lg:grid-cols-2">
    @foreach ($d['charts'] as $c)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm {{ $c['type'] === 'columns' ? 'lg:col-span-2' : '' }}" data-chart="{{ $c['id'] }}">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">{{ $c['title'] }}</h2>
            @if ($c['type'] === 'columns')
                @if (array_sum(array_map('floatval', $c['current'])) == 0 && array_sum(array_map('floatval', $c['previous'] ?? [])) == 0)
                    <p class="py-6 text-center text-sm text-slate-500">{{ __('reporting::ui.nothing') }}</p>
                @else
                    {!! SvgChart::columns($c) !!}
                    @if ($c['previous'] !== null)
                        <p class="mt-2 flex gap-4 text-xs text-slate-600">
                            <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:#4f46e5"></span>{{ __('reporting::ui.this_period') }}</span>
                            <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:#cbd5e1"></span>{{ __('reporting::ui.compare_short') }}</span>
                        </p>
                    @endif
                    <details class="mt-3 text-xs text-slate-600">
                        <summary class="cursor-pointer">{{ __('reporting::ui.table_view') }}</summary>
                        <table class="mt-2 w-full"><tbody>
                            @foreach ($c['categories'] as $i => $cat)
                                <tr class="border-t border-slate-100"><td class="py-1">{{ $cat }}</td><td class="py-1 text-end tabular-nums">{{ Format::value($c['unit'], $c['current'][$i]) }}</td>@if ($c['previous'] !== null)<td class="py-1 text-end tabular-nums text-slate-500">{{ Format::value($c['unit'], $c['previous'][$i] ?? null) }}</td>@endif</tr>
                            @endforeach
                        </tbody></table>
                    </details>
                @endif
            @else
                @php $max = collect($c['rows'])->map(fn ($r) => abs((float) $r['value']))->max() ?: 1; @endphp
                @forelse ($c['rows'] as $r)
                    <div class="mb-2 text-xs" title="{{ $r['label'] }}: {{ Format::value($c['unit'], $r['value']) }}">
                        <div class="mb-0.5 flex justify-between gap-2 text-slate-700">
                            <span class="truncate">@if (! empty($r['url'])) <a class="hover:underline" href="{{ $r['url'] }}">{{ $r['label'] }}</a> @else {{ $r['label'] }} @endif</span>
                            <span class="tabular-nums">{{ Format::value($c['unit'], $r['value']) }}</span>
                        </div>
                        <div class="h-2 rounded bg-slate-100"><div class="rp-barfill h-2 rounded" style="width: {{ max(1, round(abs((float) $r['value']) / $max * 100)) }}%"></div></div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">{{ __('reporting::ui.nothing') }}</p>
                @endforelse
            @endif
        </div>
    @endforeach
</section>

@foreach ($d['tables'] as $t)
    <section class="mb-8" data-table="{{ $t['id'] }}">
        <h2 class="mb-2 text-sm font-semibold text-slate-700">{{ $t['title'] }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>@foreach ($t['columns'] as $col)<th class="px-4 py-3 font-medium {{ in_array($col['type'], ['lkr', 'qty', 'count', 'pct', 'minutes']) ? 'text-end' : 'text-start' }}">{{ $col['label'] }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($t['rows'] as $r)
                    <tr>
                        @foreach ($t['columns'] as $i => $col)
                            <td class="px-4 py-2.5 {{ in_array($col['type'], ['lkr', 'qty', 'count', 'pct', 'minutes']) ? 'text-end tabular-nums' : '' }}">
                                @if ($i === 0 && $r['url']) <a class="text-indigo-600 hover:underline" href="{{ $r['url'] }}">{{ Format::value($col['type'], $r['cells'][$col['key']] ?? null) }}</a>
                                @else {{ Format::value($col['type'], $r['cells'][$col['key']] ?? null) }} @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500" colspan="{{ count($t['columns']) }}">{{ __('reporting::ui.nothing') }}</td></tr>
                @endforelse
            </tbody>
        </x-core::table>
    </section>
@endforeach
