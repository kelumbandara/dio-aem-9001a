@php
    $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
@endphp
<x-core::layouts.app :title="__('manufacturing::ui.setup.'.$entity.'.many')">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('manufacturing.setup.index') }}" class="text-sm text-indigo-600 hover:underline">← {{ __('manufacturing::ui.setup.title') }}</a>
        @if ($canManage)
            <a href="{{ route('manufacturing.setup.create', $entity) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('manufacturing::ui.setup.new') }}</a>
        @endif
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.code') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.name') }}</th>
                @if ($entity === 'work-centers')
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.setup.capacity') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.setup.rate') }}</th>
                @elseif ($entity === 'machines')
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.work-centers.one') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.lines.one') }}</th>
                @elseif ($entity === 'shifts')
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.hours') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.days') }}</th>
                @endif
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.status') }}</th>
                @if ($canManage) <th class="px-4 py-3"></th> @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs">{{ $r['code'] }}</td>
                    <td class="px-4 py-3">{{ $r['name'] }}</td>
                    @if ($entity === 'work-centers')
                        <td class="px-4 py-3 text-end">{{ $r['capacity_hours_per_day'] }} h</td>
                        <td class="px-4 py-3 text-end"><x-core::money :value="$r['hourly_rate'] === '' ? '0' : $r['hourly_rate']" /></td>
                    @elseif ($entity === 'machines')
                        <td class="px-4 py-3 font-mono text-xs">{{ $r['work_center'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $r['production_line'] ?? '—' }}</td>
                    @elseif ($entity === 'shifts')
                        <td class="px-4 py-3">{{ $r['start_time'] }} – {{ $r['end_time'] }}</td>
                        <td class="px-4 py-3 text-xs">{{ collect($days)->filter(fn ($d, $i) => ($r['days'][$i] ?? '0') === '1')->map(fn ($d) => __("manufacturing::ui.days.{$d}"))->implode(' ') }}</td>
                    @endif
                    <td class="px-4 py-3">
                        @php $active = $entity === 'machines' ? $r['status'] : ($r['is_active'] ? 'active' : 'inactive'); @endphp
                        <x-core::status-badge :tone="in_array($active, ['active', 'available'], true) ? 'success' : ($active === 'maintenance' ? 'warning' : 'neutral')">{{ __("manufacturing::ui.status.{$active}") }}</x-core::status-badge>
                    </td>
                    @if ($canManage) <td class="px-4 py-3 text-end"><a href="{{ route('manufacturing.setup.edit', [$entity, $r['id']]) }}" class="text-indigo-600 hover:underline">{{ __('manufacturing::ui.common.edit') }}</a></td> @endif
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('manufacturing::ui.setup.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
</x-core::layouts.app>
