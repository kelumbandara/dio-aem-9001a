@php
    $dateField = ['quotations' => 'quote_date', 'orders' => 'order_date', 'deliveries' => 'delivery_date', 'invoices' => 'invoice_date', 'credit-notes' => 'note_date'][$kind];
    $field = 'block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $tones = ['draft' => 'neutral', 'sent' => 'info', 'accepted' => 'success', 'confirmed' => 'info', 'completed' => 'success', 'posted' => 'success', 'converted' => 'success', 'rejected' => 'danger', 'cancelled' => 'danger', 'expired' => 'warning', 'reversed' => 'warning'];
@endphp
<x-core::layouts.app :title="__('sales::ui.titles.'.$kind)">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="q" class="mb-1 block text-xs font-medium text-slate-600">{{ __('sales::ui.common.search') }}</label>
                <input id="q" name="q" value="{{ $q }}" placeholder="{{ __('sales::ui.common.search_hint') }}" class="{{ $field }} w-56">
            </div>
            @if ($statuses)
                <div>
                    <label for="status" class="mb-1 block text-xs font-medium text-slate-600">{{ __('sales::ui.common.status') }}</label>
                    <select id="status" name="status" class="{{ $field }}">
                        <option value="">{{ __('sales::ui.common.all') }}</option>
                        @foreach ($statuses as $s) <option value="{{ $s }}" @selected($status === $s)>{{ __("sales::ui.status.{$s}") }}</option> @endforeach
                    </select>
                </div>
            @endif
            <x-core::button type="submit" variant="secondary">{{ __('sales::ui.common.filter') }}</x-core::button>
        </form>
        @if ($create)
            <a href="{{ $create }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('sales::ui.new.'.$kind) }}</a>
        @endif
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('sales::ui.common.document') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('sales::ui.common.customer') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('sales::ui.common.date') }}</th>
                @if (in_array($kind, ['quotations', 'orders', 'invoices', 'credit-notes'], true))
                    <th class="px-4 py-3 text-end font-medium">{{ __('sales::ui.common.total') }}</th>
                @endif
                <th class="px-4 py-3 text-start font-medium">{{ __('sales::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs"><a href="{{ route('sales.'.$kind.'.show', $r['id']) }}" class="text-indigo-600 hover:underline">{{ $r['document_no'] }}</a></td>
                    <td class="px-4 py-3">{{ $r['customer_name'] }} <span class="font-mono text-xs text-slate-500">{{ $r['customer'] }}</span></td>
                    <td class="px-4 py-3">{{ $r[$dateField] ?? '' }}</td>
                    @if (in_array($kind, ['quotations', 'orders', 'invoices', 'credit-notes'], true))
                        <td class="px-4 py-3 text-end"><x-core::money :value="$r['gross_total']" /></td>
                    @endif
                    <td class="px-4 py-3"><x-core::status-badge :tone="$tones[$r['status']] ?? 'neutral'">{{ __("sales::ui.status.{$r['status']}") }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('sales::ui.empty.'.$kind) }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $page->links() }}</div>
</x-core::layouts.app>
