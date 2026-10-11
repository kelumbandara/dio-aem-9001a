<x-core::layouts.app :title="$j['journal_no']">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <x-core::status-badge :tone="$j['status'] === 'posted' ? 'success' : 'neutral'">{{ __("finance::ui.journals.status.{$j['status']}") }}</x-core::status-badge>
            <span>{{ __("finance::ui.journals.kinds.{$j['kind']}") }} · {{ $j['entry_date'] }}</span>
            @if ($j['reverses']) <span>· {{ __('finance::ui.journals.reverses') }} {{ $j['reverses'] }}</span> @endif
            @if ($j['reversed_by']) <span>· {{ __('finance::ui.journals.reversed_by') }} {{ $j['reversed_by'] }}</span> @endif
        </div>
        <a href="{{ route('finance.journals.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('finance::ui.common.back') }}</a>
    </div>

    <dl class="mb-6 grid max-w-3xl grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm shadow-sm sm:grid-cols-4">
        <div class="col-span-2"><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.common.description') }}</dt><dd class="mt-0.5">{{ $j['description'] }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.journals.reference') }}</dt><dd class="mt-0.5">{{ $j['reference'] ?: '—' }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.journals.posted_by') }}</dt><dd class="mt-0.5">{{ $j['created_by'] ?: __('finance::ui.journals.automatic') }}</dd></div>
    </dl>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">#</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.journals.account') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.description') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.journals.debit') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.journals.credit') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($j['lines'] as $l)
                <tr>
                    <td class="px-4 py-2">{{ $l['line_no'] }}</td>
                    <td class="px-4 py-2"><span class="font-mono text-xs">{{ $l['account'] }}</span> {{ $l['account_name'] }}</td>
                    <td class="px-4 py-2">{{ $l['description'] ?: '' }}</td>
                    <td class="px-4 py-2 text-end">@if ($l['debit'] !== '0.0000')<x-core::money :value="$l['debit']" />@endif</td>
                    <td class="px-4 py-2 text-end">@if ($l['credit'] !== '0.0000')<x-core::money :value="$l['credit']" />@endif</td>
                </tr>
            @endforeach
            <tr class="bg-slate-50 font-semibold"><td colspan="3" class="px-4 py-2 text-end">{{ __('finance::ui.common.total') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$j['total']" /></td><td class="px-4 py-2 text-end"><x-core::money :value="$j['total']" /></td></tr>
        </tbody>
    </x-core::table>

    @can('finance.reverse')
        @if ($j['status'] === 'posted' && $j['kind'] !== 'reversal' && $j['kind'] !== 'system' && ! $j['source_type'])
            <form method="POST" action="{{ route('finance.journals.reverse', $model) }}" class="mt-6 max-w-xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" onsubmit="return confirm('{{ __('finance::ui.journals.reverse_confirm') }}')">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">{{ __('finance::ui.journals.reverse') }}</h2>
                <p class="text-sm text-slate-600">{{ __('finance::ui.journals.reverse_hint') }}</p>
                <x-core::input name="note" :label="__('finance::ui.journals.reverse_note')" maxlength="500" />
                <x-core::button type="submit" variant="danger">{{ __('finance::ui.journals.reverse') }}</x-core::button>
            </form>
        @endif
    @endcan
</x-core::layouts.app>
