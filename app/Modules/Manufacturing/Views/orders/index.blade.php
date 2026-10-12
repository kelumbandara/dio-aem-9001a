@php
    $field = 'block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $tones = ['draft' => 'neutral', 'released' => 'info', 'in_progress' => 'warning', 'completed' => 'success', 'closed' => 'success', 'cancelled' => 'danger'];
@endphp
<x-core::layouts.app :title="__('manufacturing::ui.orders.title')">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="q" class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.common.search') }}</label>
                <input id="q" name="q" value="{{ $q }}" placeholder="{{ __('manufacturing::ui.orders.search_hint') }}" class="{{ $field }} w-56">
            </div>
            <div>
                <label for="status" class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.common.status') }}</label>
                <select id="status" name="status" class="{{ $field }}">
                    <option value="">{{ __('manufacturing::ui.common.all') }}</option>
                    @foreach ($statuses as $s) <option value="{{ $s }}" @selected($status === $s)>{{ __("manufacturing::ui.status.{$s}") }}</option> @endforeach
                </select>
            </div>
            <x-core::button type="submit" variant="secondary">{{ __('manufacturing::ui.common.filter') }}</x-core::button>
        </form>
        @if ($canManage)
            <a href="{{ route('manufacturing.orders.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('manufacturing::ui.orders.new') }}</a>
        @endif
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.orders.order') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.item') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.planned') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.orders.dates') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs"><a href="{{ route('manufacturing.orders.show', $r['id']) }}" class="text-indigo-600 hover:underline">{{ $r['order_no'] }}</a></td>
                    <td class="px-4 py-3"><span class="font-mono text-xs">{{ $r['item'] }}</span> {{ $r['item_name'] }}</td>
                    <td class="px-4 py-3 text-end">{{ $r['planned_qty'] }} {{ $r['uom'] }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $r['planned_start'] }} → {{ $r['planned_end'] }}</td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$tones[$r['status']] ?? 'neutral'">{{ __("manufacturing::ui.status.{$r['status']}") }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('manufacturing::ui.orders.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $page->links() }}</div>
</x-core::layouts.app>
