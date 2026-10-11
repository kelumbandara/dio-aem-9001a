@php
    $num = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('inventory::ui.reorder.title')">
    <section class="mb-8">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('inventory::ui.reorder.below') }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.item') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.warehouse') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.available') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.reorder.min') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.reorder.order_qty') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($below as $b)
                    <tr>
                        <td class="px-4 py-3"><a href="{{ route('inventory.stock.item', $b['item_code']) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $b['item_code'] }}</a> {{ $b['item_name'] }}</td>
                        <td class="px-4 py-3">{{ $b['warehouse'] ?? __('inventory::ui.reorder.company') }}</td>
                        <td class="px-4 py-3 text-end text-red-700">{{ $num($b['available']) }} {{ $b['uom'] }}</td>
                        <td class="px-4 py-3 text-end">{{ $num($b['min_qty']) }}</td>
                        <td class="px-4 py-3 text-end">{{ $b['reorder_qty'] !== null ? $num($b['reorder_qty']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('inventory::ui.reorder.none_below') }}</td></tr>
                @endforelse
            </tbody>
        </x-core::table>
    </section>

    <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('inventory::ui.reorder.rules') }}</h2>
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.item') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.warehouse') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.reorder.min') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.reorder.order_qty') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rules as $r)
                        @php $m = $r['model']; @endphp
                        <tr>
                            <td class="px-4 py-3"><span class="font-mono text-xs">{{ $r['code'] }}</span> {{ $r['name'] }}</td>
                            <td class="px-4 py-3">{{ $m->warehouse?->code ?? __('inventory::ui.reorder.company') }}</td>
                            @if ($canManage)
                                <td colspan="3" class="px-4 py-2">
                                    <form method="POST" action="{{ route('inventory.reorder.update', $m) }}" class="flex flex-wrap items-center justify-end gap-2">
                                        @csrf
                                        <input name="min_qty" value="{{ $num($m->min_qty) }}" aria-label="{{ __('inventory::ui.reorder.min') }}" class="w-24 rounded-lg border-0 px-2 py-1 text-end text-sm ring-1 ring-inset ring-slate-300">
                                        <input name="reorder_qty" value="{{ $m->reorder_qty !== null ? $num($m->reorder_qty) : '' }}" aria-label="{{ __('inventory::ui.reorder.order_qty') }}" class="w-24 rounded-lg border-0 px-2 py-1 text-end text-sm ring-1 ring-inset ring-slate-300">
                                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" value="1" @checked($m->is_active)> {{ __('inventory::ui.common.active') }}</label>
                                        <button type="submit" class="text-sm text-indigo-600 hover:underline">{{ __('inventory::ui.common.save') }}</button>
                                    </form>
                                </td>
                            @else
                                <td class="px-4 py-3 text-end">{{ $num($m->min_qty) }} {{ $r['uom'] }}</td>
                                <td class="px-4 py-3 text-end">{{ $m->reorder_qty !== null ? $num($m->reorder_qty) : '—' }}</td>
                                <td></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('inventory::ui.reorder.no_rules') }}</td></tr>
                    @endforelse
                </tbody>
            </x-core::table>
        </div>

        @if ($canManage)
            <section class="h-fit rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('inventory::ui.reorder.add') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('inventory::ui.reorder.base_unit_note') }}</p>
                <form method="POST" action="{{ route('inventory.reorder.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <x-core::input name="item" :label="__('inventory::ui.common.item')" :value="old('item')" required maxlength="40" />
                    <div>
                        <label for="f-wh" class="mb-1 block text-sm font-medium text-slate-700">{{ __('inventory::ui.common.warehouse') }}</label>
                        <select id="f-wh" name="warehouse" class="{{ $field }}">
                            <option value="">{{ __('inventory::ui.reorder.company') }}</option>
                            @foreach ($warehouses as $w) <option value="{{ $w->code }}" @selected(old('warehouse') === $w->code)>{{ $w->code }} — {{ $w->name }}</option> @endforeach
                        </select>
                    </div>
                    <x-core::input name="min_qty" inputmode="decimal" :label="__('inventory::ui.reorder.min')" :value="old('min_qty')" required />
                    <x-core::input name="reorder_qty" inputmode="decimal" :label="__('inventory::ui.reorder.order_qty')" :value="old('reorder_qty')" />
                    <x-core::button type="submit">{{ __('inventory::ui.reorder.add') }}</x-core::button>
                </form>
            </section>
        @endif
    </section>
</x-core::layouts.app>
