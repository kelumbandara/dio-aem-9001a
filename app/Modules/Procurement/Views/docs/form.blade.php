@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $editing = $doc !== null;
    $inclusive = $editing ? $doc['prices_include_vat'] : (bool) config('procurement.prices_include_vat');
    $today = now(config('core.display_timezone'))->toDateString();
    $num = fn ($v) => $v === null ? '' : (str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v);
    $blank = ['item' => '', 'qty' => '', 'uom' => '', 'unit_price' => '', 'discount_pct' => '', 'tax_code' => '', 'description' => ''];
    $existing = $editing ? collect($doc['lines'])->map(fn ($l) => ['item' => $l['item'], 'qty' => $num($l['qty']), 'uom' => '', 'unit_price' => $num($l['unit_price']), 'discount_pct' => $num($l['discount_pct']), 'tax_code' => $l['tax_code'] ?? '', 'description' => $l['description'] ?? ''])->all() : [$blank];
    $rows = collect(old('lines', $existing))->map(fn ($r) => array_merge($blank, (array) $r))->values();
    $v = fn ($key, $default = null) => old($key, $editing ? ($doc[$key] ?? $default) : $default);
@endphp
<x-core::layouts.app :title="$editing ? $doc['document_no'] : __('procurement::ui.new_order')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('procurement::ui.form.intro') }}</p>

    <form method="POST" action="{{ $editing ? route('procurement.orders.update', $doc['id']) : route('procurement.orders.store') }}" class="max-w-6xl space-y-6" x-data="{ rows: @js($rows) }">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $doc['version'] }}"> @endif
        @foreach (['form', 'version'] as $e) @error($e) <x-core::alert type="error">{{ $message }}</x-core::alert> @enderror @endforeach

        <section class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="f-supplier" class="mb-1 block text-sm font-medium text-slate-700">{{ __('procurement::ui.common.supplier') }}</label>
                <input id="f-supplier" name="supplier" list="suppliers" value="{{ $v('supplier') }}" required autocomplete="off" class="{{ $field }}" placeholder="SUP-00001">
                <datalist id="suppliers">@foreach ($options['suppliers'] as $s) <option value="{{ $s['code'] }}">{{ $s['name'] }}</option> @endforeach</datalist>
                @error('supplier') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="f-warehouse" class="mb-1 block text-sm font-medium text-slate-700">{{ __('procurement::ui.form.deliver_to') }}</label>
                <select id="f-warehouse" name="warehouse" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($options['warehouses'] as $id => $label) @php $code = explode(' ', $label)[0]; @endphp <option value="{{ $code }}" @selected(old('warehouse', $editing ? ($doc['warehouse'] ? explode(' ', $doc['warehouse'])[0] : null) : null) === $code)>{{ $label }}</option> @endforeach
                </select>
                @error('warehouse') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-core::input name="order_date" type="date" :label="__('procurement::ui.common.date')" :value="$v('order_date', $today)" />
            <x-core::input name="expected_date" type="date" :label="__('procurement::ui.form.expected_date')" :value="$v('expected_date')" />
            <x-core::input name="supplier_ref" :label="__('procurement::ui.form.supplier_ref')" :value="$v('supplier_ref')" maxlength="100" />
            <x-core::input name="payment_terms_days" type="number" min="0" max="365" :label="__('procurement::ui.form.terms_days')" :value="$v('payment_terms_days')" :hint="__('procurement::ui.form.terms_hint')" />
            <x-core::input name="notes" :label="__('procurement::ui.form.notes')" :value="$v('notes')" />
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('procurement::ui.common.lines') }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ $inclusive ? __('procurement::ui.form.lines_hint_incl') : __('procurement::ui.form.lines_hint') }}</p>
            @error('lines') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <datalist id="item-codes">@foreach ($options['items'] as $i) <option value="{{ $i['code'] }}">{{ $i['name'] }}</option> @endforeach</datalist>
            <datalist id="tax-codes">@foreach ($options['taxCodes'] as $t) <option value="{{ $t['code'] }}">{{ $t['name'] }}</option> @endforeach</datalist>

            <div class="mt-3 space-y-3">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid grid-cols-2 items-end gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-6 lg:grid-cols-12">
                        <div class="col-span-2 sm:col-span-3 lg:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.item') }}</label>
                            <input :name="`lines[${i}][item]`" x-model="row.item" list="item-codes" autocomplete="off" class="{{ $field }}" placeholder="ITM-00001">
                        </div>
                        <div class="sm:col-span-1 lg:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.qty') }}</label>
                            <input :name="`lines[${i}][qty]`" x-model="row.qty" inputmode="decimal" class="{{ $field }}">
                        </div>
                        <div class="sm:col-span-1 lg:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.unit') }}</label>
                            <input :name="`lines[${i}][uom]`" x-model="row.uom" class="{{ $field }}" placeholder="{{ __('procurement::ui.form.base') }}">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ $inclusive ? __('procurement::ui.common.unit_price_incl') : __('procurement::ui.common.unit_price') }}</label>
                            <input :name="`lines[${i}][unit_price]`" x-model="row.unit_price" inputmode="decimal" class="{{ $field }}" placeholder="{{ __('procurement::ui.form.price_list') }}">
                        </div>
                        <div class="sm:col-span-1 lg:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.discount') }}</label>
                            <input :name="`lines[${i}][discount_pct]`" x-model="row.discount_pct" inputmode="decimal" class="{{ $field }}" placeholder="0">
                        </div>
                        <div class="sm:col-span-1 lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.tax') }}</label>
                            <input :name="`lines[${i}][tax_code]`" x-model="row.tax_code" list="tax-codes" class="{{ $field }}" placeholder="{{ __('procurement::ui.form.item_default') }}">
                        </div>
                        <div class="text-end"><button type="button" class="text-sm text-red-600 hover:underline" @click="rows.length > 1 ? rows.splice(i, 1) : null">{{ __('procurement::ui.common.remove') }}</button></div>
                        <div class="col-span-2 sm:col-span-6 lg:col-span-12">
                            <input :name="`lines[${i}][description]`" x-model="row.description" maxlength="255" class="{{ $field }}" placeholder="{{ __('procurement::ui.form.description') }}">
                        </div>
                    </div>
                </template>
            </div>

            @if ($errors->any())
                <ul class="mt-3 list-disc ps-5 text-sm text-red-600">
                    @foreach (collect($errors->messages())->filter(fn ($m, $k) => str_starts_with($k, 'lines.'))->all() as $key => $messages)
                        <li>{{ __('procurement::ui.form.line_n', ['n' => ((int) (explode('.', $key)[1] ?? 0)) + 1]) }}: {{ $messages[0] }}</li>
                    @endforeach
                </ul>
            @endif

            <button type="button" class="mt-3 text-sm text-indigo-600 hover:underline" @click='rows.push(@js($blank))'>+ {{ __('procurement::ui.form.add_line') }}</button>
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('procurement::ui.common.save') }}</x-core::button>
            <a href="{{ route('procurement.orders.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('procurement::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
