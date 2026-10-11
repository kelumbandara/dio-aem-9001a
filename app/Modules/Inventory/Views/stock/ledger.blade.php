<x-core::layouts.app :title="__('inventory::ui.stock.ledger')">
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <x-core::input name="item" :label="__('inventory::ui.common.item')" :value="$f['item'] ?? ''" class="w-40" />
        <div>
            <label for="f-warehouse" class="mb-1 block text-sm font-medium text-slate-700">{{ __('inventory::ui.common.warehouse') }}</label>
            <select id="f-warehouse" name="warehouse" class="block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                <option value="">{{ __('inventory::ui.common.all') }}</option>
                @foreach ($warehouses as $w) <option value="{{ $w->code }}" @selected(($f['warehouse'] ?? '') === $w->code)>{{ $w->code }}</option> @endforeach
            </select>
        </div>
        <x-core::input name="from" type="date" :label="__('inventory::ui.common.from')" :value="$f['from'] ?? ''" />
        <x-core::input name="to" type="date" :label="__('inventory::ui.common.to')" :value="$f['to'] ?? ''" />
        <x-core::button type="submit" variant="secondary">{{ __('inventory::ui.common.filter') }}</x-core::button>
    </form>
    @include('inventory::stock.partials.ledger-table', ['rows' => $rows, 'showItem' => true])
</x-core::layouts.app>
