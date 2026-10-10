@php
    $tone = ['pending' => 'warning', 'approved' => 'info', 'executed' => 'success', 'rejected' => 'danger', 'execution_failed' => 'danger'];
    $tabs = ['inbox' => 'approvals::ui.tabs.inbox', 'mine' => 'approvals::ui.tabs.mine'] + ($canViewAll ? ['all' => 'approvals::ui.tabs.all'] : []);
@endphp
<x-core::layouts.app :title="__('approvals::ui.title')">
    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Tabs">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('approvals.index', ['scope' => $key]) }}"
               @class([
                   'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                   'border-indigo-600 text-indigo-700' => $scope === $key,
                   'border-transparent text-slate-600 hover:text-slate-900' => $scope !== $key,
               ])
               @if ($scope === $key) aria-current="page" @endif>{{ __($label) }}</a>
        @endforeach
    </nav>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.col.document') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.col.summary') }}</th>
                <th class="hidden px-4 py-3 text-end font-medium md:table-cell">{{ __('approvals::ui.col.amount') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium lg:table-cell">{{ __('approvals::ui.col.requested_by') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.col.status') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium sm:table-cell">{{ __('approvals::ui.col.expires') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($requests as $r)
                <tr class="hover:bg-slate-50">
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs"><a class="text-indigo-700 hover:underline" href="{{ route('approvals.show', $r->public_id) }}">{{ $r->document_no }}</a></td>
                    <td class="px-4 py-3">{{ $r->summary }}</td>
                    <td class="hidden px-4 py-3 text-end md:table-cell"><x-core::money :value="$r->amount" /></td>
                    <td class="hidden px-4 py-3 text-slate-600 lg:table-cell">{{ $names[$r->requested_by] ?? '' }}</td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$tone[$r->status] ?? 'neutral'">{{ __('approvals::ui.status.'.$r->status) }}</x-core::status-badge></td>
                    <td class="hidden whitespace-nowrap px-4 py-3 text-slate-600 sm:table-cell"><x-core::datetime :value="$r->expires_at" /></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('approvals::ui.empty.'.$scope) }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>

    <div class="mt-4">{{ $requests->links() }}</div>
</x-core::layouts.app>
