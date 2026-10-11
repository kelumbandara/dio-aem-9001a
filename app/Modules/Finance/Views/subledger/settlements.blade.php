@php $r = $side === 'receivable' ? 'finance.receivables.' : 'finance.payables.'; $k = $side === 'receivable' ? 'receivables' : 'payables'; @endphp
<x-core::layouts.app :title="__('finance::ui.'.$k.'.settlements')">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex gap-2 text-sm">
            <a href="{{ route($r.'index') }}" class="rounded-lg bg-white px-3 py-1.5 text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">{{ __('finance::ui.sub.open') }}</a>
            <a href="{{ route($r.'settlements.index') }}" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-white">{{ __('finance::ui.'.$k.'.settlements') }}</a>
        </nav>
        @if ($canPost)
            <a href="{{ route($r.'settlements.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('finance::ui.'.$k.'.new_settlement') }}</a>
        @endif
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.sub.document') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.'.$k.'.party') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.date') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.sub.method') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.common.amount') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.sub.unallocated') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $s)
                <tr>
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route($r.'settlements.show', $s['id']) }}" class="text-indigo-600 hover:underline">{{ $s['document_no'] }}</a></td>
                    <td class="px-4 py-2">{{ $s['party'] }}</td>
                    <td class="px-4 py-2">{{ $s['date'] }}</td>
                    <td class="px-4 py-2">{{ __("finance::ui.methods.{$s['method']}") }}</td>
                    <td class="px-4 py-2 text-end"><x-core::money :value="$s['amount']" /></td>
                    <td class="px-4 py-2 text-end"><x-core::money :value="$s['unallocated']" /></td>
                    <td class="px-4 py-2"><x-core::status-badge :tone="$s['status'] === 'posted' ? 'success' : 'neutral'">{{ __("finance::ui.journals.status.{$s['status']}") }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ __('finance::ui.'.$k.'.no_settlements') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $page->links() }}</div>
</x-core::layouts.app>
