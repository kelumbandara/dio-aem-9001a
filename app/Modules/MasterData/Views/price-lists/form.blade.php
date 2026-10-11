@php
    $select = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600 disabled:bg-slate-100 disabled:text-slate-500';
    $editing = $list !== null;
@endphp
<x-core::layouts.app :title="$editing ? __('masterdata::ui.price_lists.edit_title', ['code' => $list->code]) : __('masterdata::ui.price_lists.new')">
    <form method="POST" action="{{ $editing ? route('price-lists.update', $list) : route('price-lists.store') }}" class="max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $list->version }}"> @endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($editing)
                <div><span class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.common.code') }}</span><p class="py-2 font-mono text-sm">{{ $list->code }}</p></div>
            @else
                <x-core::input name="code" :label="__('masterdata::ui.common.code')" :hint="__('masterdata::ui.common.auto_code')" :value="old('code')" maxlength="20" />
            @endif
            <x-core::input name="name" :label="__('masterdata::ui.common.name')" :value="old('name', $list?->name)" required maxlength="150" />
            <div>
                <label for="f-type" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.price_lists.type') }}</label>
                <select id="f-type" name="type" class="{{ $select }}" @disabled($editing)>
                    @foreach (['sales', 'purchase'] as $t) <option value="{{ $t }}" @selected(old('type', $list?->type ?? 'sales') === $t)>{{ __("masterdata::ui.price_lists.types.{$t}") }}</option> @endforeach
                </select>
                @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="f-currency" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.parties.currency') }}</label>
                <select id="f-currency" name="currency" required class="{{ $select }}">
                    @foreach ($currencies as $c) <option value="{{ $c->code }}" @selected(old('currency', $list?->currency->code ?? 'LKR') === $c->code)>{{ $c->code }} — {{ $c->name }}</option> @endforeach
                </select>
                @error('currency') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-core::input name="valid_from" type="date" :label="__('masterdata::ui.price_lists.valid_from')" :value="old('valid_from', $list?->valid_from?->toDateString())" />
            <x-core::input name="valid_to" type="date" :label="__('masterdata::ui.price_lists.valid_to')" :value="old('valid_to', $list?->valid_to?->toDateString())" />
        </div>
        <fieldset class="space-y-2 text-sm">
            @foreach (['tax_inclusive' => false, 'is_default' => false, 'is_active' => true] as $flag => $default)
                <input type="hidden" name="{{ $flag }}" value="0">
                <label class="flex items-center gap-2"><input type="checkbox" name="{{ $flag }}" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old($flag, $list?->{$flag} ?? $default))> {{ __("masterdata::ui.price_lists.{$flag}") }}</label>
            @endforeach
        </fieldset>
        <div class="flex gap-3 pt-2">
            <x-core::button type="submit">{{ __('masterdata::ui.common.save') }}</x-core::button>
            <a href="{{ $editing ? route('price-lists.show', $list) : route('price-lists.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('masterdata::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
