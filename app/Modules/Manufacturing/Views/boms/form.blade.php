@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $editing = $bom !== null;
    $blankC = ['item' => '', 'qty' => '', 'uom' => '', 'scrap_pct' => '', 'notes' => ''];
    $blankO = ['op_no' => '', 'name' => '', 'work_center' => '', 'machine' => '', 'setup_minutes' => '', 'run_minutes' => '', 'notes' => ''];
    $comps = $editing ? collect($bom['components'])->map(fn ($c) => array_merge($blankC, ['item' => $c['item'], 'qty' => $c['qty'], 'uom' => $c['uom'], 'scrap_pct' => $c['scrap_pct'] === '0' ? '' : $c['scrap_pct'], 'notes' => $c['notes'] ?? '']))->all() : [$blankC];
    $ops = $editing ? collect($bom['operations'])->map(fn ($o) => array_merge($blankO, ['op_no' => $o['op_no'], 'name' => $o['name'], 'work_center' => $o['work_center'], 'machine' => $o['machine'] ?? '', 'setup_minutes' => $o['setup_minutes'] === '0' ? '' : $o['setup_minutes'], 'run_minutes' => $o['run_minutes'] === '0' ? '' : $o['run_minutes'], 'notes' => $o['notes'] ?? '']))->all() : [];
    $compRows = collect(old('components', $comps))->map(fn ($r) => array_merge($blankC, (array) $r))->values();
    $opRows = collect(old('operations', $ops))->map(fn ($r) => array_merge($blankO, (array) $r))->values();
    $v = fn ($key, $default = null) => old($key, $editing ? ($bom[$key] ?? $default) : $default);
@endphp
<x-core::layouts.app :title="$editing ? $bom['item'].' · v'.$bom['version_no'] : __('manufacturing::ui.boms.new')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('manufacturing::ui.boms.form_intro') }}</p>

    <form method="POST" action="{{ $editing ? route('manufacturing.boms.update', $bom['id']) : route('manufacturing.boms.store') }}" class="max-w-6xl space-y-6" x-data="{ comps: @js($compRows), ops: @js($opRows) }">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $bom['version'] }}"> @endif

        <section class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="f-item" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.boms.product') }}</label>
                @if ($editing)
                    <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm"><span class="font-mono text-xs">{{ $bom['item'] }}</span> {{ $bom['item_name'] }}</div>
                @else
                    <input id="f-item" name="item" list="products" value="{{ old('item', $prefill) }}" required autocomplete="off" class="{{ $field }}" placeholder="ITM-00001">
                    <datalist id="products">@foreach ($options['items'] as $i) <option value="{{ $i['code'] }}">{{ $i['name'] }}</option> @endforeach</datalist>
                    @error('item') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
            </div>
            <div>
                <x-core::input name="base_qty" inputmode="decimal" :label="__('manufacturing::ui.boms.base_qty')" :value="$v('base_qty', '1')" :hint="__('manufacturing::ui.boms.base_qty_hint')" />
            </div>
            <div>
                <x-core::input name="description" :label="__('manufacturing::ui.common.notes')" :value="$v('description')" maxlength="255" />
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.components') }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ __('manufacturing::ui.boms.components_hint') }}</p>
            @error('components') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <datalist id="item-codes">@foreach ($options['items'] as $i) <option value="{{ $i['code'] }}">{{ $i['name'] }}</option> @endforeach</datalist>
            <div class="mt-3 space-y-3">
                <template x-for="(row, i) in comps" :key="i">
                    <div class="grid grid-cols-2 items-end gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-6 lg:grid-cols-12">
                        <div class="col-span-2 sm:col-span-3 lg:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.common.item') }}</label>
                            <input :name="`components[${i}][item]`" x-model="row.item" list="item-codes" autocomplete="off" class="{{ $field }}" placeholder="ITM-00001">
                        </div>
                        <div class="sm:col-span-1 lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.common.qty') }}</label>
                            <input :name="`components[${i}][qty]`" x-model="row.qty" inputmode="decimal" class="{{ $field }}">
                        </div>
                        <div class="sm:col-span-1 lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.common.unit') }}</label>
                            <input :name="`components[${i}][uom]`" x-model="row.uom" class="{{ $field }}" placeholder="{{ __('manufacturing::ui.common.base') }}">
                        </div>
                        <div class="sm:col-span-1 lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.boms.scrap') }}</label>
                            <input :name="`components[${i}][scrap_pct]`" x-model="row.scrap_pct" inputmode="decimal" class="{{ $field }}" placeholder="0">
                        </div>
                        <div class="text-end sm:col-span-1 lg:col-span-2"><button type="button" class="text-sm text-red-600 hover:underline" @click="comps.length > 1 ? comps.splice(i, 1) : null">{{ __('manufacturing::ui.common.remove') }}</button></div>
                        <div class="col-span-2 sm:col-span-6 lg:col-span-12">
                            <input :name="`components[${i}][notes]`" x-model="row.notes" maxlength="255" class="{{ $field }}" placeholder="{{ __('manufacturing::ui.common.notes') }}">
                        </div>
                    </div>
                </template>
            </div>
            <button type="button" class="mt-3 text-sm font-medium text-indigo-600 hover:underline" @click="comps.push({ item: '', qty: '', uom: '', scrap_pct: '', notes: '' })">+ {{ __('manufacturing::ui.boms.add_component') }}</button>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.routing') }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ __('manufacturing::ui.boms.routing_hint') }}</p>
            @error('operations') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <datalist id="center-codes">@foreach ($options['workCenters'] as $c) <option value="{{ $c['code'] }}">{{ $c['name'] }}</option> @endforeach</datalist>
            <datalist id="machine-codes">@foreach ($options['machines'] as $m) <option value="{{ $m['code'] }}">{{ $m['name'] }} ({{ $m['work_center'] }})</option> @endforeach</datalist>
            <div class="mt-3 space-y-3">
                <template x-for="(row, i) in ops" :key="i">
                    <div class="grid grid-cols-2 items-end gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-6 lg:grid-cols-12">
                        <div class="lg:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.boms.step') }}</label>
                            <input :name="`operations[${i}][op_no]`" x-model="row.op_no" inputmode="numeric" class="{{ $field }}" placeholder="10">
                        </div>
                        <div class="col-span-2 sm:col-span-3 lg:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.common.name') }}</label>
                            <input :name="`operations[${i}][name]`" x-model="row.name" maxlength="150" class="{{ $field }}">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.setup.work-centers.one') }}</label>
                            <input :name="`operations[${i}][work_center]`" x-model="row.work_center" list="center-codes" autocomplete="off" class="{{ $field }}">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.setup.machines.one') }}</label>
                            <input :name="`operations[${i}][machine]`" x-model="row.machine" list="machine-codes" autocomplete="off" class="{{ $field }}">
                        </div>
                        <div class="lg:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.boms.setup_min') }}</label>
                            <input :name="`operations[${i}][setup_minutes]`" x-model="row.setup_minutes" inputmode="decimal" class="{{ $field }}" placeholder="0">
                        </div>
                        <div class="lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.boms.run_min') }}</label>
                            <input :name="`operations[${i}][run_minutes]`" x-model="row.run_minutes" inputmode="decimal" class="{{ $field }}" placeholder="0">
                        </div>
                        <div class="text-end lg:col-span-1"><button type="button" class="text-sm text-red-600 hover:underline" @click="ops.splice(i, 1)">{{ __('manufacturing::ui.common.remove') }}</button></div>
                        <div class="col-span-2 sm:col-span-6 lg:col-span-12">
                            <input :name="`operations[${i}][notes]`" x-model="row.notes" maxlength="255" class="{{ $field }}" placeholder="{{ __('manufacturing::ui.common.notes') }}">
                        </div>
                    </div>
                </template>
            </div>
            <button type="button" class="mt-3 text-sm font-medium text-indigo-600 hover:underline" @click="ops.push({ op_no: '', name: '', work_center: '', machine: '', setup_minutes: '', run_minutes: '', notes: '' })">+ {{ __('manufacturing::ui.boms.add_step') }}</button>

            @if ($errors->any())
                <ul class="mt-3 list-disc ps-5 text-sm text-red-600">
                    @foreach (collect($errors->messages())->filter(fn ($m, $k) => str_starts_with($k, 'components.') || str_starts_with($k, 'operations.'))->all() as $key => $messages)
                        @php [$kind, $n] = explode('.', $key); @endphp
                        <li>{{ __($kind === 'components' ? 'manufacturing::ui.boms.component_n' : 'manufacturing::ui.boms.step_n', ['n' => ((int) $n) + 1]) }}: {{ $messages[0] }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('manufacturing::ui.common.save') }}</x-core::button>
            <a href="{{ $editing ? route('manufacturing.boms.show', $bom['id']) : route('manufacturing.boms.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('manufacturing::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
