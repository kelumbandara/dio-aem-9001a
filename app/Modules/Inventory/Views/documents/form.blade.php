@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $blank = ['item' => '', 'qty' => '', 'uom' => '', 'warehouse' => '', 'location' => '', 'to_warehouse' => '', 'to_location' => '', 'direction' => 'out', 'lot' => '', 'expiry_date' => '', 'unit_cost' => '', 'note' => ''];
    $rows = collect(old('lines', [$blank]))->map(fn ($r) => array_merge($blank, (array) $r))->values();
    $today = now(config('core.display_timezone'))->toDateString();
    $needsCost = in_array($type, ['receipt', 'adjustment'], true);
@endphp
<x-core::layouts.app :title="__('inventory::ui.documents.new_'.$type)">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('inventory::ui.documents.intro_'.$type) }}</p>

    <form method="POST" action="{{ route('inventory.documents.store') }}" class="max-w-6xl space-y-6" x-data="{ rows: @js($rows) }">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">

        <section class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <x-core::input name="posting_date" type="date" :label="__('inventory::ui.common.date')" :value="old('posting_date', $today)" :max="$today" required />
            <div>
                <label for="f-reason" class="mb-1 block text-sm font-medium text-slate-700">{{ __('inventory::ui.documents.reason') }}</label>
                <select id="f-reason" name="reason" required class="{{ $field }}">
                    @foreach ($reasons as $r) <option value="{{ $r }}" @selected(old('reason', $reasons[0]) === $r)>{{ __("inventory::ui.documents.reasons.{$r}") }}</option> @endforeach
                </select>
                @error('reason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-core::input name="reference" :label="__('inventory::ui.documents.reference')" :value="old('reference')" maxlength="100" />
            <x-core::input name="notes" :label="__('inventory::ui.documents.notes')" :value="old('notes')" />
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('inventory::ui.documents.lines') }}</h2>
            @error('lines') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <datalist id="item-codes">
                @foreach ($itemCodes as $i) <option value="{{ $i['code'] }}">{{ $i['name'] }}{{ $i['lot'] ? ' ('.__('inventory::ui.documents.by_lot').')' : '' }}</option> @endforeach
            </datalist>

            <div class="mt-3 space-y-4">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="rounded-lg border border-slate-200 p-3">
                        <div class="grid grid-cols-2 items-end gap-2 sm:grid-cols-6 lg:grid-cols-12">
                            <div class="col-span-2 sm:col-span-3 lg:col-span-3">
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.common.item') }}</label>
                                <input :name="`lines[${i}][item]`" x-model="row.item" list="item-codes" autocomplete="off" class="{{ $field }}" placeholder="ITM-00001">
                            </div>
                            @if ($type === 'adjustment')
                                <div class="sm:col-span-2 lg:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.documents.direction') }}</label>
                                    <select :name="`lines[${i}][direction]`" x-model="row.direction" class="{{ $field }}">
                                        <option value="out">{{ __('inventory::ui.documents.dir_out') }}</option>
                                        <option value="in">{{ __('inventory::ui.documents.dir_in') }}</option>
                                    </select>
                                </div>
                            @endif
                            <div class="sm:col-span-2 lg:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.stock.qty') }}</label>
                                <input :name="`lines[${i}][qty]`" x-model="row.qty" inputmode="decimal" class="{{ $field }}">
                            </div>
                            <div class="sm:col-span-1 lg:col-span-1">
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.documents.unit') }}</label>
                                <input :name="`lines[${i}][uom]`" x-model="row.uom" class="{{ $field }}" placeholder="{{ __('inventory::ui.documents.base') }}">
                            </div>
                            <div class="sm:col-span-2 lg:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ $type === 'transfer' ? __('inventory::ui.documents.from') : __('inventory::ui.common.warehouse') }}</label>
                                <select :name="`lines[${i}][warehouse]`" x-model="row.warehouse" class="{{ $field }}">
                                    <option value="">—</option>
                                    @foreach ($warehouses as $w) <option value="{{ $w->code }}">{{ $w->code }}</option> @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2 lg:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.common.location') }}</label>
                                <input :name="`lines[${i}][location]`" x-model="row.location" class="{{ $field }}" placeholder="{{ __('inventory::ui.documents.optional') }}">
                            </div>
                            @if ($type === 'transfer')
                                <div class="sm:col-span-2 lg:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.documents.to') }}</label>
                                    <select :name="`lines[${i}][to_warehouse]`" x-model="row.to_warehouse" class="{{ $field }}">
                                        <option value="">—</option>
                                        @foreach ($warehouses as $w) <option value="{{ $w->code }}">{{ $w->code }}</option> @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-2 lg:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.documents.to_location') }}</label>
                                    <input :name="`lines[${i}][to_location]`" x-model="row.to_location" class="{{ $field }}" placeholder="{{ __('inventory::ui.documents.optional') }}">
                                </div>
                            @endif
                            <div class="sm:col-span-2 lg:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.common.lot') }}</label>
                                <input :name="`lines[${i}][lot]`" x-model="row.lot" class="{{ $field }}" placeholder="{{ $type === 'receipt' ? __('inventory::ui.documents.lot_new') : __('inventory::ui.documents.lot_fefo') }}">
                            </div>
                            @if ($type === 'receipt')
                                <div class="sm:col-span-2 lg:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.lots.expiry') }}</label>
                                    <input type="date" :name="`lines[${i}][expiry_date]`" x-model="row.expiry_date" class="{{ $field }}">
                                </div>
                            @endif
                            @if ($needsCost)
                                <div class="sm:col-span-2 lg:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.documents.unit_cost') }}</label>
                                    <input :name="`lines[${i}][unit_cost]`" x-model="row.unit_cost" inputmode="decimal" class="{{ $field }}" placeholder="{{ $type === 'adjustment' ? __('inventory::ui.documents.cost_avg') : '' }}">
                                </div>
                            @endif
                            <div class="col-span-2 sm:col-span-4 lg:col-span-4">
                                <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.documents.note') }}</label>
                                <input :name="`lines[${i}][note]`" x-model="row.note" maxlength="255" class="{{ $field }}">
                            </div>
                            <div class="text-end"><button type="button" class="text-sm text-red-600 hover:underline" @click="rows.length > 1 ? rows.splice(i, 1) : null">{{ __('inventory::ui.common.remove') }}</button></div>
                        </div>
                    </div>
                </template>
            </div>

            @if ($errors->any())
                <ul class="mt-3 list-disc ps-5 text-sm text-red-600">
                    @foreach (collect($errors->messages())->filter(fn ($m, $k) => str_starts_with($k, 'lines.'))->all() as $key => $messages)
                        <li>{{ __('inventory::ui.documents.line_n', ['n' => ((int) (explode('.', $key)[1] ?? 0)) + 1]) }}: {{ $messages[0] }}</li>
                    @endforeach
                </ul>
            @endif

            <button type="button" class="mt-3 text-sm text-indigo-600 hover:underline" @click='rows.push(@js($blank))'>+ {{ __('inventory::ui.documents.add_line') }}</button>
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('inventory::ui.documents.post') }}</x-core::button>
            <a href="{{ route('inventory.documents.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('inventory::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
