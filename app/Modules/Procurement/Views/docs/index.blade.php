@php
    $dateField = ['orders' => 'order_date', 'receipts' => 'receipt_date', 'bills' => 'bill_date', 'returns' => 'return_date'][$kind];
    $field = 'block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $tones = ['draft' => 'neutral', 'pending_approval' => 'warning', 'approved' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'posted' => 'success', 'reversed' => 'warning'];
    $hasTotal = in_array($kind, ['orders', 'bills', 'returns'], true);
@endphp
<x-core::layouts.app :title="__('procurement::ui.titles.'.$kind)">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="q" class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.search') }}</label>
                <input id="q" name="q" value="{{ $q }}" placeholder="{{ __('procurement::ui.common.search_hint') }}" class="{{ $field }} w-56">
            </div>
            @if ($statuses)
                <div>
                    <label for="status" class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.status') }}</label>
                    <select id="status" name="status" class="{{ $field }}">
                        <option value="">{{ __('procurement::ui.common.all') }}</option>
                        @foreach ($statuses as $s) <option value="{{ $s }}" @selected($status === $s)>{{ __("procurement::ui.status.{$s}") }}</option> @endforeach
                    </select>
                </div>
            @endif
            <x-core::button type="submit" variant="secondary">{{ __('procurement::ui.common.filter') }}</x-core::button>
        </form>
        @if ($create)
            <a href="{{ $create }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('procurement::ui.new_order') }}</a>
        @endif
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('procurement::ui.common.document') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('procurement::ui.common.supplier') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('procurement::ui.common.date') }}</th>
                @if ($hasTotal) <th class="px-4 py-3 text-end font-medium">{{ __('procurement::ui.common.total') }}</th> @endif
                <th class="px-4 py-3 text-start font-medium">{{ __('procurement::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs">
                        <a href="{{ route('procurement.'.$kind.'.show', $r['id']) }}" class="text-indigo-600 hover:underline">{{ $r['document_no'] }}</a>
                        @if (! empty($r['supplier_invoice_no'])) <div class="font-sans text-slate-500">{{ $r['supplier_invoice_no'] }}</div> @endif
                    </td>
                    <td class="px-4 py-3">{{ $r['supplier_name'] }} <span class="font-mono text-xs text-slate-500">{{ $r['supplier'] }}</span></td>
                    <td class="px-4 py-3">{{ $r[$dateField] ?? '' }}</td>
                    @if ($hasTotal) <td class="px-4 py-3 text-end"><x-core::money :value="$r['gross_total']" /></td> @endif
                    <td class="px-4 py-3"><x-core::status-badge :tone="$tones[$r['status']] ?? 'neutral'">{{ __("procurement::ui.status.{$r['status']}") }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('procurement::ui.empty.'.$kind) }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $page->links() }}</div>
</x-core::layouts.app>
