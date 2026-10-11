@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $blank = ['account' => '', 'debit' => '', 'credit' => '', 'description' => ''];
    $rows = collect(old('lines', [$blank, $blank]))->map(fn ($r) => array_merge($blank, (array) $r))->values();
@endphp
<x-core::layouts.app :title="__('finance::ui.journals.new')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('finance::ui.journals.intro') }}</p>

    <form method="POST" action="{{ route('finance.journals.store') }}" class="max-w-5xl space-y-6" x-data="{ rows: @js($rows), num(v) { const n = parseFloat(v); return isNaN(n) ? 0 : n; }, get debit() { return this.rows.reduce((s, r) => s + this.num(r.debit), 0); }, get credit() { return this.rows.reduce((s, r) => s + this.num(r.credit), 0); } }">
        @csrf
        <section class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3">
            <x-core::input name="entry_date" type="date" :label="__('finance::ui.common.date')" :value="old('entry_date', now(config('core.display_timezone'))->toDateString())" :max="now(config('core.display_timezone'))->toDateString()" required />
            <x-core::input name="description" :label="__('finance::ui.common.description')" :value="old('description')" required maxlength="255" class="sm:col-span-2" />
            <x-core::input name="reference" :label="__('finance::ui.journals.reference')" :value="old('reference')" maxlength="100" />
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('finance::ui.journals.lines') }}</h2>
            <datalist id="account-codes">
                @foreach ($accounts as $a) <option value="{{ $a->code }}">{{ $a->name }}</option> @endforeach
            </datalist>
            <div class="mt-3 space-y-2">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid grid-cols-12 items-center gap-2">
                        <input :name="`lines[${i}][account]`" x-model="row.account" list="account-codes" autocomplete="off" placeholder="{{ __('finance::ui.journals.account') }}" class="{{ $field }} col-span-3">
                        <input :name="`lines[${i}][description]`" x-model="row.description" placeholder="{{ __('finance::ui.common.description') }}" class="{{ $field }} col-span-3">
                        <input :name="`lines[${i}][debit]`" x-model="row.debit" inputmode="decimal" placeholder="{{ __('finance::ui.journals.debit') }}" class="{{ $field }} col-span-2 text-end">
                        <input :name="`lines[${i}][credit]`" x-model="row.credit" inputmode="decimal" placeholder="{{ __('finance::ui.journals.credit') }}" class="{{ $field }} col-span-2 text-end">
                        <button type="button" class="col-span-2 text-sm text-red-600 hover:underline" @click="rows.length > 2 ? rows.splice(i, 1) : null">{{ __('finance::ui.common.remove') }}</button>
                    </div>
                </template>
            </div>
            <button type="button" class="mt-3 text-sm text-indigo-600 hover:underline" @click='rows.push(@js($blank))'>+ {{ __('finance::ui.journals.add_line') }}</button>

            <div class="mt-4 flex flex-wrap justify-end gap-6 border-t border-slate-100 pt-3 text-sm">
                <span>{{ __('finance::ui.journals.debit') }}: <strong x-text="debit.toFixed(2)"></strong></span>
                <span>{{ __('finance::ui.journals.credit') }}: <strong x-text="credit.toFixed(2)"></strong></span>
                <span :class="Math.abs(debit - credit) < 0.005 ? 'text-emerald-700' : 'text-red-700'"><span x-text="Math.abs(debit - credit) < 0.005 ? '{{ __('finance::ui.journals.balanced') }}' : '{{ __('finance::ui.journals.difference') }} ' + Math.abs(debit - credit).toFixed(2)"></span></span>
            </div>
            @foreach (collect($errors->messages())->filter(fn ($m, $k) => str_starts_with($k, 'lines')) as $key => $messages)
                <p class="mt-2 text-sm text-red-600">{{ $messages[0] }}</p>
            @endforeach
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('finance::ui.journals.post') }}</x-core::button>
            <a href="{{ route('finance.journals.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('finance::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
