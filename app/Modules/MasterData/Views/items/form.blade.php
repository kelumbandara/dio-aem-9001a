@php
    $select = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600 disabled:bg-slate-100 disabled:text-slate-500';
    $editing = $item !== null;
    $rows = old('uoms', $editing ? $item->uoms->map(fn ($u) => ['uom' => $u->uom->code, 'factor' => rtrim(rtrim($u->factor, '0'), '.'), 'for_purchase' => $u->for_purchase, 'for_sale' => $u->for_sale])->all() : []);
@endphp
<x-core::layouts.app :title="$editing ? __('masterdata::ui.items.edit_title', ['code' => $item->code]) : __('masterdata::ui.items.new')">
    <form method="POST" action="{{ $editing ? route('items.update', $item) : route('items.store') }}" class="max-w-3xl space-y-6" x-data="{ rows: @js(array_values($rows)) }">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $item->version }}"> @endif

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($editing)
                    <div><span class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.common.code') }}</span><p class="py-2 font-mono text-sm">{{ $item->code }}</p></div>
                @else
                    <x-core::input name="code" :label="__('masterdata::ui.common.code')" :hint="__('masterdata::ui.common.auto_code')" :value="old('code')" maxlength="40" />
                @endif
                <x-core::input name="name" :label="__('masterdata::ui.common.name')" :value="old('name', $item?->name)" required maxlength="255" />
            </div>

            <div>
                <label for="f-description" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.items.description') }}</label>
                <textarea id="f-description" name="description" rows="2" maxlength="5000" class="{{ $select }}">{{ old('description', $item?->description) }}</textarea>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="f-category" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.items.category') }}</label>
                    <select id="f-category" name="category" required class="{{ $select }}">
                        @foreach ($categories as $c) <option value="{{ $c->code }}" @selected(old('category', $item?->category->code) === $c->code)>{{ $c->code }} — {{ $c->name }}</option> @endforeach
                    </select>
                    @error('category') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-item_type" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.items.type') }}</label>
                    <select id="f-item_type" name="item_type" class="{{ $select }}" @disabled($editing)>
                        @foreach ($types as $t) <option value="{{ $t }}" @selected(old('item_type', $item?->item_type ?? 'stock') === $t)>{{ __("masterdata::ui.items.types.{$t}") }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-base_uom" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.items.base_uom') }}</label>
                    <select id="f-base_uom" name="base_uom" required class="{{ $select }}" @disabled($editing)>
                        @foreach ($uoms as $u) <option value="{{ $u->code }}" @selected(old('base_uom', $item?->baseUom->code ?? 'PCS') === $u->code)>{{ $u->code }} — {{ $u->name }}</option> @endforeach
                    </select>
                    @error('base_uom') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            @if ($editing) <p class="text-xs text-slate-500">{{ __('masterdata::ui.items.locked_note') }}</p> @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="f-tracking" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.items.tracking') }}</label>
                    <select id="f-tracking" name="tracking" class="{{ $select }}" @disabled($editing)>
                        @foreach (['none', 'lot'] as $t) <option value="{{ $t }}" @selected(old('tracking', $item?->tracking ?? 'none') === $t)>{{ __("masterdata::ui.items.tracking_{$t}") }}</option> @endforeach
                    </select>
                    @error('tracking') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-core::input name="shelf_life_days" type="number" min="1" :label="__('masterdata::ui.items.shelf_life')" :value="old('shelf_life_days', $item?->shelf_life_days)" />
                <x-core::input name="barcode" :label="__('masterdata::ui.items.barcode')" :value="old('barcode', $item?->barcode)" maxlength="64" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="f-default_tax_code" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.items.tax_code') }}</label>
                    <select id="f-default_tax_code" name="default_tax_code" class="{{ $select }}">
                        <option value="">—</option>
                        @foreach ($taxCodes as $t) <option value="{{ $t->code }}" @selected(old('default_tax_code', $item?->defaultTaxCode?->code) === $t->code)>{{ $t->code }} — {{ $t->name }}</option> @endforeach
                    </select>
                </div>
                <fieldset class="space-y-2 pt-6 text-sm">
                    @foreach (['is_sellable' => true, 'is_purchasable' => true, 'is_active' => true] as $flag => $default)
                        <input type="hidden" name="{{ $flag }}" value="0">
                        <label class="flex items-center gap-2"><input type="checkbox" name="{{ $flag }}" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old($flag, $item?->{$flag} ?? $default))> {{ __("masterdata::ui.items.{$flag}") }}</label>
                    @endforeach
                </fieldset>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.items.alt_units') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('masterdata::ui.items.alt_units_hint') }}</p>
            <div class="mt-3 space-y-2">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid grid-cols-12 items-center gap-2">
                        <select :name="`uoms[${i}][uom]`" x-model="row.uom" class="{{ $select }} col-span-4">
                            <option value="">—</option>
                            @foreach ($uoms as $u) <option value="{{ $u->code }}">{{ $u->code }}</option> @endforeach
                        </select>
                        <input :name="`uoms[${i}][factor]`" x-model="row.factor" inputmode="decimal" placeholder="{{ __('masterdata::ui.items.factor') }}" class="{{ $select }} col-span-3">
                        <input type="hidden" :name="`uoms[${i}][for_purchase]`" value="0"><label class="col-span-2 flex items-center gap-1 text-xs"><input type="checkbox" :name="`uoms[${i}][for_purchase]`" value="1" x-model="row.for_purchase"> {{ __('masterdata::ui.items.for_purchase') }}</label>
                        <input type="hidden" :name="`uoms[${i}][for_sale]`" value="0"><label class="col-span-2 flex items-center gap-1 text-xs"><input type="checkbox" :name="`uoms[${i}][for_sale]`" value="1" x-model="row.for_sale"> {{ __('masterdata::ui.items.for_sale') }}</label>
                        <button type="button" class="col-span-1 text-sm text-red-600 hover:underline" @click="rows.splice(i, 1)" aria-label="{{ __('masterdata::ui.common.remove') }}">✕</button>
                    </div>
                </template>
            </div>
            <button type="button" class="mt-3 text-sm text-indigo-600 hover:underline" @click="rows.push({ uom: '', factor: '', for_purchase: true, for_sale: true })">+ {{ __('masterdata::ui.items.add_unit') }}</button>
            @error('uoms') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('masterdata::ui.common.save') }}</x-core::button>
            <a href="{{ $editing ? route('items.show', $item) : route('items.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('masterdata::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
