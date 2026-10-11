@php
    $r = $side === 'receivable' ? 'finance.receivables.' : 'finance.payables.'; $k = $side === 'receivable' ? 'receivables' : 'payables';
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $today = now(config('core.display_timezone'))->toDateString();
@endphp
<x-core::layouts.app :title="__('finance::ui.'.$k.'.new_settlement')">
    <form method="GET" action="{{ route($r.'settlements.create') }}" class="mb-4 flex max-w-xl items-end gap-2">
        <x-core::input name="party" :label="__('finance::ui.'.$k.'.party_code')" :value="$party?->code ?? request('party')" required class="w-48" />
        <x-core::button type="submit" variant="secondary">{{ __('finance::ui.sub.find_open') }}</x-core::button>
        @if ($party) <span class="pb-2 text-sm text-slate-700">{{ $party->name }}</span> @endif
    </form>

    <form method="POST" action="{{ route($r.'settlements.store') }}" class="max-w-3xl space-y-6">
        @csrf
        <input type="hidden" name="party" value="{{ $party?->code ?? old('party', request('party')) }}">
        <section class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3">
            <x-core::input name="date" type="date" :label="__('finance::ui.common.date')" :value="old('date', $today)" :max="$today" required />
            <x-core::input name="amount" inputmode="decimal" :label="__('finance::ui.common.amount').' (LKR)'" :value="old('amount')" required />
            <div>
                <label for="f-bank" class="mb-1 block text-sm font-medium text-slate-700">{{ __('finance::ui.sub.bank') }}</label>
                <select id="f-bank" name="bank_account" required class="{{ $field }}">
                    @foreach ($banks as $b) <option value="{{ $b->public_id }}" @selected(old('bank_account') === $b->public_id)>{{ $b->name }}</option> @endforeach
                </select>
                @error('bank_account') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="f-method" class="mb-1 block text-sm font-medium text-slate-700">{{ __('finance::ui.sub.method') }}</label>
                <select id="f-method" name="method" class="{{ $field }}">
                    @foreach ($methods as $m) <option value="{{ $m }}" @selected(old('method', 'bank_transfer') === $m)>{{ __("finance::ui.methods.{$m}") }}</option> @endforeach
                </select>
            </div>
            <x-core::input name="reference" :label="__('finance::ui.journals.reference')" :value="old('reference')" maxlength="100" />
            <x-core::input name="notes" :label="__('finance::ui.accounts.notes')" :value="old('notes')" maxlength="500" />
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('finance::ui.sub.settle') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('finance::ui.sub.settle_hint') }}</p>
            @if ($openItems->isEmpty())
                <p class="mt-3 text-sm text-slate-500">{{ $party ? __('finance::ui.sub.nothing_open_party') : __('finance::ui.sub.pick_party') }}</p>
            @else
                <x-core::table class="mt-3">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">{{ __('finance::ui.sub.document') }}</th>
                            <th class="px-4 py-2 text-start font-medium">{{ __('finance::ui.sub.due') }}</th>
                            <th class="px-4 py-2 text-end font-medium">{{ __('finance::ui.sub.open_amount') }}</th>
                            <th class="px-4 py-2 text-end font-medium">{{ __('finance::ui.sub.settle_now') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($openItems as $n => $i)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $i->document_no }}<input type="hidden" name="allocations[{{ $n }}][open_item]" value="{{ $i->public_id }}"></td>
                                <td class="px-4 py-2">{{ $i->due_date->toDateString() }}</td>
                                <td class="px-4 py-2 text-end"><x-core::money :value="$i->open_amount" /></td>
                                <td class="px-4 py-2 text-end"><input name="allocations[{{ $n }}][amount]" value="{{ old("allocations.{$n}.amount") }}" inputmode="decimal" class="w-32 rounded-lg border-0 px-2 py-1 text-end text-sm ring-1 ring-inset ring-slate-300"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-core::table>
            @endif
            @foreach (collect($errors->messages())->filter(fn ($m, $key) => str_starts_with($key, 'allocations')) as $messages)
                <p class="mt-2 text-sm text-red-600">{{ $messages[0] }}</p>
            @endforeach
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('finance::ui.common.save') }}</x-core::button>
            <a href="{{ route($r.'index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('finance::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
