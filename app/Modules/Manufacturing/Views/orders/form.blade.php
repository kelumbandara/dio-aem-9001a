@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $editing = $order !== null;
    $today = now(config('core.display_timezone'))->toDateString();
    $v = fn ($key, $default = null) => old($key, $editing ? ($order[$key] ?? $default) : $default);
@endphp
<x-core::layouts.app :title="$editing ? $order['order_no'] : __('manufacturing::ui.orders.new')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('manufacturing::ui.orders.form_intro') }}</p>
    <form method="POST" action="{{ $editing ? route('manufacturing.orders.update', $order['id']) : route('manufacturing.orders.store') }}" class="max-w-4xl space-y-6">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $order['version'] }}"> @endif

        <section class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="f-item" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.orders.product') }}</label>
                @if ($editing)
                    <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm"><span class="font-mono text-xs">{{ $order['item'] }}</span> {{ $order['item_name'] }}</div>
                    <input type="hidden" name="item" value="{{ $order['item'] }}">
                @else
                    <input id="f-item" name="item" list="products" value="{{ old('item', $prefill) }}" required autocomplete="off" class="{{ $field }}" placeholder="ITM-00001">
                    <datalist id="products">@foreach ($options['items'] as $i) <option value="{{ $i['code'] }}">{{ $i['name'] }}</option> @endforeach</datalist>
                    @error('item') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
            </div>
            <x-core::input name="qty" inputmode="decimal" :label="__('manufacturing::ui.orders.planned').($editing ? ' ('.$order['uom'].')' : '')" :value="$v('planned_qty')" required />
            <div>
                <label for="f-line" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.setup.lines.one') }}</label>
                <select id="f-line" name="line" class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($options['lines'] as $l) <option value="{{ $l['code'] }}" @selected(old('line', $editing ? $order['line'] : '') === $l['code'])>{{ $l['code'] }} {{ $l['name'] }}</option> @endforeach
                </select>
                @error('line') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-core::input name="planned_start" type="date" :label="__('manufacturing::ui.orders.start')" :value="$v('planned_start', $today)" />
            <x-core::input name="planned_end" type="date" :label="__('manufacturing::ui.orders.finish')" :value="$v('planned_end', $today)" />
            @foreach (['source_warehouse' => 'source', 'output_warehouse' => 'output'] as $name => $key)
                <div>
                    <label for="f-{{ $name }}" class="mb-1 block text-sm font-medium text-slate-700">{{ __("manufacturing::ui.orders.{$key}_warehouse") }}</label>
                    <select id="f-{{ $name }}" name="{{ $name }}" required class="{{ $field }}">
                        <option value="">—</option>
                        @foreach ($options['warehouses'] as $id => $label) @php $code = explode(' ', $label)[0]; @endphp
                            <option value="{{ $code }}" @selected(old($name, $editing ? $order[$name.'_code'] : null) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error($name) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endforeach
            <div class="sm:col-span-4"><x-core::input name="notes" :label="__('manufacturing::ui.common.notes')" :value="$v('notes')" /></div>
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('manufacturing::ui.common.save') }}</x-core::button>
            <a href="{{ $editing ? route('manufacturing.orders.show', $order['id']) : route('manufacturing.orders.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('manufacturing::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
