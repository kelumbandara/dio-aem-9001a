<x-core::layouts.app :title="__('finance::ui.journals.title')">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="q" class="mb-1 block text-xs font-medium text-slate-600">{{ __('finance::ui.common.search') }}</label>
                <input id="q" name="q" value="{{ $q }}" placeholder="{{ __('finance::ui.journals.search_hint') }}" class="block w-56 rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
            </div>
            <div>
                <label for="kind" class="mb-1 block text-xs font-medium text-slate-600">{{ __('finance::ui.journals.kind') }}</label>
                <select id="kind" name="kind" class="block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                    <option value="">{{ __('finance::ui.common.all') }}</option>
                    @foreach (['manual', 'system', 'reversal', 'opening'] as $k) <option value="{{ $k }}" @selected($kind === $k)>{{ __("finance::ui.journals.kinds.{$k}") }}</option> @endforeach
                </select>
            </div>
            <x-core::input name="from" type="date" :label="__('finance::ui.common.from')" :value="$from" />
            <x-core::input name="to" type="date" :label="__('finance::ui.common.to')" :value="$to" />
            <x-core::button type="submit" variant="secondary">{{ __('finance::ui.common.filter') }}</x-core::button>
        </form>
        @can('finance.journal.post')
            <a href="{{ route('finance.journals.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('finance::ui.journals.new') }}</a>
        @endcan
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.journals.number') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.date') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.description') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.journals.kind') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.common.amount') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs"><a href="{{ route('finance.journals.show', $r['id']) }}" class="text-indigo-600 hover:underline">{{ $r['journal_no'] }}</a></td>
                    <td class="px-4 py-3">{{ $r['entry_date'] }}</td>
                    <td class="px-4 py-3">{{ $r['description'] }}@if ($r['reference'])<div class="text-xs text-slate-500">{{ $r['reference'] }}</div>@endif</td>
                    <td class="px-4 py-3">{{ __("finance::ui.journals.kinds.{$r['kind']}") }}</td>
                    <td class="px-4 py-3 text-end"><x-core::money :value="$r['total']" /></td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$r['status'] === 'posted' ? 'success' : 'neutral'">{{ __("finance::ui.journals.status.{$r['status']}") }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('finance::ui.journals.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $page->links() }}</div>
</x-core::layouts.app>
