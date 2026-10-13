@php
    $tones = ['draft' => 'neutral', 'active' => 'success', 'retired' => 'warning'];
    $isDraft = $bom['status'] === 'draft';
@endphp
<x-core::layouts.app :title="$bom['item'].' · v'.$bom['version_no']">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-lg font-semibold text-slate-900"><span class="font-mono text-base">{{ $bom['item'] }}</span> {{ $bom['item_name'] }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <x-core::status-badge :tone="$tones[$bom['status']] ?? 'neutral'">{{ __("manufacturing::ui.status.{$bom['status']}") }}</x-core::status-badge>
                <span>{{ __('manufacturing::ui.boms.version') }} {{ $bom['version_no'] }}</span>
                <span>· {{ __('manufacturing::ui.boms.makes') }} {{ $bom['base_qty'] }} {{ $bom['uom'] }}</span>
                @if ($bom['effective_from']) <span>· {{ $bom['effective_from'] }}@if ($bom['effective_to']) → {{ $bom['effective_to'] }} @endif</span> @endif
            </div>
            @if ($bom['description']) <p class="mt-1 text-sm text-slate-600">{{ $bom['description'] }}</p> @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($isDraft && $canManage)
                <a href="{{ route('manufacturing.boms.edit', $bom['id']) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('manufacturing::ui.common.edit') }}</a>
            @endif
            @if ($isDraft && $canActivate)
                <form method="POST" action="{{ route('manufacturing.boms.activate', $bom['id']) }}" x-on:submit="if (! confirm(@js(__('manufacturing::ui.boms.confirm_activate')))) $event.preventDefault()">@csrf
                    <x-core::button type="submit">{{ __('manufacturing::ui.boms.activate') }}</x-core::button></form>
            @endif
            @if ($bom['status'] === 'active' && $canActivate)
                <form method="POST" action="{{ route('manufacturing.boms.retire', $bom['id']) }}" x-on:submit="if (! confirm(@js(__('manufacturing::ui.boms.confirm_retire')))) $event.preventDefault()">@csrf
                    <x-core::button type="submit" variant="secondary">{{ __('manufacturing::ui.boms.retire') }}</x-core::button></form>
            @endif
            @if ($canManage)
                <form method="POST" action="{{ route('manufacturing.boms.copy', $bom['id']) }}">@csrf
                    <x-core::button type="submit" variant="secondary">{{ __('manufacturing::ui.boms.copy') }}</x-core::button></form>
            @endif
            @if ($isDraft && $canManage)
                <form method="POST" action="{{ route('manufacturing.boms.destroy', $bom['id']) }}" x-on:submit="if (! confirm(@js(__('manufacturing::ui.boms.confirm_delete')))) $event.preventDefault()">@csrf @method('DELETE')
                    <x-core::button type="submit" variant="danger">{{ __('manufacturing::ui.common.delete') }}</x-core::button></form>
            @endif
            @if ($bom['status'] === 'active' && auth()->user()->can('manufacturing.order.manage'))
                <a href="{{ route('manufacturing.orders.create', ['item' => $bom['item']]) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('manufacturing::ui.boms.make_order') }}</a>
            @endif
        </div>
    </div>

    <section class="mb-6">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.components') }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">#</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.item') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.common.qty') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.unit') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.boms.scrap') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($bom['components'] as $c)
                    <tr>
                        <td class="px-4 py-3 text-slate-500">{{ $c['line_no'] }}</td>
                        <td class="px-4 py-3"><span class="font-mono text-xs">{{ $c['item'] }}</span> {{ $c['item_name'] }}</td>
                        <td class="px-4 py-3 text-end">{{ $c['qty'] }}</td>
                        <td class="px-4 py-3">{{ $c['uom'] }}</td>
                        <td class="px-4 py-3 text-end">{{ $c['scrap_pct'] }}%</td>
                        <td class="px-4 py-3 text-slate-600">{{ $c['notes'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-core::table>
    </section>

    <section class="mb-6">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.routing') }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.boms.step') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.name') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.work-centers.one') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.machines.one') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.boms.setup_min') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.boms.run_min') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($bom['operations'] as $o)
                    <tr>
                        <td class="px-4 py-3 text-slate-500">{{ $o['op_no'] }}</td>
                        <td class="px-4 py-3">{{ $o['name'] }}@if ($o['notes']) <div class="text-xs text-slate-500">{{ $o['notes'] }}</div> @endif</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $o['work_center'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $o['machine'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-end">{{ $o['setup_minutes'] }}</td>
                        <td class="px-4 py-3 text-end">{{ $o['run_minutes'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">{{ __('manufacturing::ui.boms.no_routing') }}</td></tr>
                @endforelse
            </tbody>
        </x-core::table>
    </section>

    @if ($bom['tree'] !== [])
        <section class="mb-6">
            <h2 class="mb-1 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.indented') }}</h2>
            <p class="mb-2 text-xs text-slate-500">{{ __('manufacturing::ui.boms.indented_hint', ['qty' => $bom['base_qty'], 'uom' => $bom['uom']]) }}</p>
            <x-core::table>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($bom['tree'] as $r)
                        <tr>
                            <td class="px-4 py-2" style="padding-inline-start: {{ 1 + $r['level'] * 1.25 }}rem">
                                <span class="font-mono text-xs">{{ $r['code'] }}</span> {{ $r['name'] }}
                                @if (! $r['leaf']) <span class="ms-1 text-xs text-slate-500">({{ __('manufacturing::ui.boms.made_from', ['v' => $r['version_no']]) }})</span> @endif
                            </td>
                            <td class="px-4 py-2 text-end">{{ \App\Support\Decimal::trim($r['qty']) }} {{ $r['uom'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-core::table>
        </section>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.versions') }}</h2>
            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm shadow-sm">
                @foreach ($versions as $v)
                    <li class="flex items-center justify-between px-4 py-2">
                        <a href="{{ route('manufacturing.boms.show', $v['id']) }}" class="text-indigo-600 hover:underline">v{{ $v['version_no'] }}</a>
                        <span class="text-xs text-slate-500">{{ $v['effective_from'] ?? '' }}@if ($v['effective_to']) → {{ $v['effective_to'] }} @endif</span>
                        <x-core::status-badge :tone="$tones[$v['status']] ?? 'neutral'">{{ __("manufacturing::ui.status.{$v['status']}") }}</x-core::status-badge>
                    </li>
                @endforeach
            </ul>
        </section>
        <section>
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.where_used') }}</h2>
            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm shadow-sm">
                @forelse ($whereUsed as $u)
                    <li class="px-4 py-2"><span class="font-mono text-xs">{{ $u['code'] }}</span> {{ $u['name'] }} <span class="text-xs text-slate-500">v{{ $u['version_no'] }}</span></li>
                @empty
                    <li class="px-4 py-3 text-slate-500">{{ __('manufacturing::ui.boms.not_used') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-core::layouts.app>
