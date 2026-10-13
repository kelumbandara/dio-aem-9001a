@php $r = $side === 'receivable' ? 'finance.receivables.' : 'finance.payables.'; $k = $side === 'receivable' ? 'receivables' : 'payables'; @endphp
<x-core::layouts.app :title="$s['document_no']">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <x-core::status-badge :tone="$s['status'] === 'posted' ? 'success' : 'neutral'">{{ __("finance::ui.journals.status.{$s['status']}") }}</x-core::status-badge>
            <span>{{ $s['party'] }} · {{ $s['date'] }} · {{ __("finance::ui.methods.{$s['method']}") }}</span>
        </div>
        <a href="{{ route($r.'settlements.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('finance::ui.common.back') }}</a>
    </div>

    <dl class="mb-6 grid max-w-3xl grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm shadow-sm sm:grid-cols-4">
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.common.amount') }}</dt><dd class="mt-0.5 text-lg font-semibold"><x-core::money :value="$s['amount']" /></dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.sub.unallocated') }}</dt><dd class="mt-0.5 text-lg font-semibold"><x-core::money :value="$s['unallocated']" /></dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.sub.bank') }}</dt><dd class="mt-0.5">{{ $s['bank_account'] }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.journals.reference') }}</dt><dd class="mt-0.5">{{ $s['reference'] ?: '—' }}</dd></div>
        @if ($s['notes']) <div class="col-span-full"><dd class="whitespace-pre-line">{{ $s['notes'] }}</dd></div> @endif
    </dl>

    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('finance::ui.sub.settled') }}</h2>
    <x-core::table class="max-w-3xl">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr><th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.sub.document') }}</th><th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.common.amount') }}</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($s['allocations'] as $a)
                <tr><td class="px-4 py-2 font-mono text-xs">{{ $a['document_no'] }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$a['amount']" /></td></tr>
            @empty
                <tr><td colspan="2" class="px-4 py-6 text-center text-slate-500">{{ __('finance::ui.sub.on_account_only') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>

    @if ($canPost && $s['status'] === 'posted' && $s['unallocated'] !== '0.0000' && $openItems->isNotEmpty())
        <form method="POST" action="{{ route($r.'settlements.allocate', $model) }}" class="mt-6 max-w-3xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <h2 class="text-base font-semibold text-slate-900">{{ __('finance::ui.sub.allocate_rest') }} (<x-core::money :value="$s['unallocated']" />)</h2>
            @foreach ($openItems as $n => $i)
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="font-mono text-xs">{{ $i->document_no }}</span><span>{{ __('finance::ui.sub.open_amount') }}: <x-core::money :value="$i->open_amount" /></span>
                    <input type="hidden" name="allocations[{{ $n }}][open_item]" value="{{ $i->public_id }}">
                    <input name="allocations[{{ $n }}][amount]" inputmode="decimal" class="w-32 rounded-lg border-0 px-2 py-1 text-end text-sm ring-1 ring-inset ring-slate-300">
                </div>
            @endforeach
            <x-core::button type="submit">{{ __('finance::ui.sub.allocate') }}</x-core::button>
        </form>
    @endif

    @if ($canReverse && $s['status'] === 'posted')
        <form method="POST" action="{{ route($r.'settlements.reverse', $model) }}" class="mt-6 max-w-xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-on:submit="if (! confirm(@js(__('finance::ui.journals.reverse_confirm')))) $event.preventDefault()">
            @csrf
            <h2 class="text-base font-semibold text-slate-900">{{ __('finance::ui.sub.reverse') }}</h2>
            <p class="text-sm text-slate-600">{{ __('finance::ui.sub.reverse_hint') }}</p>
            <x-core::input name="note" :label="__('finance::ui.journals.reverse_note')" maxlength="500" />
            <x-core::button type="submit" variant="danger">{{ __('finance::ui.sub.reverse') }}</x-core::button>
        </form>
    @endif
</x-core::layouts.app>
