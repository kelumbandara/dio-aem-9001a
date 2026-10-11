@php
    $select = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $fields = $definition->fields();
    $formAction = $editing ? route('masterdata.reference.update', [$key, $definition->identifier($editing)]) : route('masterdata.reference.store', $key);
    $current = $editing ? $definition->present($editing) : [];
@endphp
<x-core::layouts.app :title="__('masterdata::ui.reference.title')">
    <nav class="mb-4 flex flex-wrap gap-2 text-sm" aria-label="{{ __('masterdata::ui.reference.title') }}">
        @foreach ($registry->keys() as $k)
            <a href="{{ route('masterdata.reference.index', $k) }}" class="rounded-lg px-3 py-1.5 {{ $k === $key ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' }}">{{ __("masterdata::ui.reference.kinds.{$k}") }}</a>
        @endforeach
    </nav>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        @foreach ($definition->columns() as $col => $type)
                            <th class="px-4 py-3 text-start font-medium">{{ __("masterdata::ui.reference.cols.{$col}") }}</th>
                        @endforeach
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr>
                            @foreach ($definition->columns() as $col => $type)
                                @php $v = $row['data'][$col] ?? null; @endphp
                                <td class="px-4 py-3 {{ $col === 'code' ? 'font-mono text-xs' : '' }}">
                                    @if ($type === 'bool' && $col === 'is_active') <x-core::status-badge :tone="$v ? 'success' : 'neutral'">{{ __($v ? 'masterdata::ui.common.active' : 'masterdata::ui.common.inactive') }}</x-core::status-badge>
                                    @elseif ($type === 'badge') <x-core::status-badge>{{ $v }}</x-core::status-badge>
                                    @elseif (is_bool($v)) {{ __($v ? 'masterdata::ui.common.yes' : 'masterdata::ui.common.no') }}
                                    @else {{ $v === null || $v === '' ? '—' : $v }} @endif
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap px-4 py-3 text-end text-sm">
                                @if (in_array($key, ['currencies', 'tax-codes'], true)) <a href="{{ route('masterdata.reference.index', [$key, 'detail' => $row['data']['code']]) }}" class="text-indigo-600 hover:underline">{{ __('masterdata::ui.reference.rates') }}</a> @endif
                                @if ($canManage) <a href="{{ route('masterdata.reference.index', [$key, 'edit' => $definition->identifier($row['model'])]) }}" class="ms-3 text-indigo-600 hover:underline">{{ __('masterdata::ui.common.edit') }}</a> @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($definition->columns()) + 1 }}" class="px-4 py-8 text-center text-slate-500">{{ __('masterdata::ui.reference.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </x-core::table>

            @if ($detail)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.reference.rates_of', ['code' => $detail['model']->code]) }}</h2>
                    @if ($detail['type'] === 'currency' && $detail['model']->is_base)
                        <p class="mt-2 text-sm text-slate-600">{{ __('masterdata::ui.reference.base_note') }}</p>
                    @else
                        <x-core::table class="mt-3">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    @if ($detail['type'] === 'currency')
                                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.reference.effective_on') }}</th>
                                        <th class="px-4 py-3 text-end font-medium">{{ __('masterdata::ui.reference.rate_per', ['code' => $detail['model']->code]) }}</th>
                                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.reference.source') }}</th>
                                    @else
                                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.reference.from') }}</th>
                                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.reference.to') }}</th>
                                        <th class="px-4 py-3 text-end font-medium">{{ __('masterdata::ui.reference.percent') }}</th>
                                        <th class="px-4 py-3"></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($detail['rates'] as $r)
                                    <tr>
                                        @if ($detail['type'] === 'currency')
                                            <td class="px-4 py-3">{{ $r->effective_on->toDateString() }}</td>
                                            <td class="px-4 py-3 text-end">{{ rtrim(rtrim($r->rate, '0'), '.') }}</td>
                                            <td class="px-4 py-3">{{ $r->source ?: '—' }}</td>
                                        @else
                                            <td class="px-4 py-3">{{ $r->effective_from->toDateString() }}</td>
                                            <td class="px-4 py-3">{{ $r->effective_to?->toDateString() ?? '—' }}</td>
                                            <td class="px-4 py-3 text-end">{{ rtrim(rtrim($r->rate, '0'), '.') }}%</td>
                                            <td class="px-4 py-3 text-end">
                                                @if ($canManage && $r->effective_from->toDateString() > now(config('core.display_timezone'))->toDateString())
                                                    <form method="POST" action="{{ route('masterdata.tax-codes.rates.destroy', [$detail['model'], $r]) }}">@csrf @method('DELETE')<button type="submit" class="text-sm text-red-600 hover:underline">{{ __('masterdata::ui.common.remove') }}</button></form>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">{{ __('masterdata::ui.reference.no_rates') }}</td></tr>
                                @endforelse
                            </tbody>
                        </x-core::table>

                        @if ($canManage)
                            @if ($detail['type'] === 'currency')
                                <form method="POST" action="{{ route('masterdata.currencies.rates.store', $detail['model']) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                                    @csrf
                                    <x-core::input name="effective_on" type="date" :label="__('masterdata::ui.reference.effective_on')" :value="old('effective_on', now(config('core.display_timezone'))->toDateString())" required />
                                    <x-core::input name="rate" inputmode="decimal" :label="__('masterdata::ui.reference.rate_per', ['code' => $detail['model']->code])" :value="old('rate')" required />
                                    <x-core::input name="source" :label="__('masterdata::ui.reference.source')" :value="old('source')" maxlength="50" />
                                    <div class="flex items-end"><x-core::button type="submit">{{ __('masterdata::ui.reference.add_rate') }}</x-core::button></div>
                                </form>
                            @else
                                <form method="POST" action="{{ route('masterdata.tax-codes.rates.store', $detail['model']) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                                    @csrf
                                    <x-core::input name="rate" inputmode="decimal" :label="__('masterdata::ui.reference.percent')" :value="old('rate')" required />
                                    <x-core::input name="effective_from" type="date" :label="__('masterdata::ui.reference.from')" :value="old('effective_from')" required />
                                    <x-core::input name="effective_to" type="date" :label="__('masterdata::ui.reference.to')" :value="old('effective_to')" />
                                    <div class="flex items-end"><x-core::button type="submit">{{ __('masterdata::ui.reference.add_rate') }}</x-core::button></div>
                                </form>
                                <p class="mt-2 text-xs text-slate-500">{{ __('masterdata::ui.reference.tax_hint') }}</p>
                            @endif
                        @endif
                    @endif
                </section>
            @endif
        </div>

        @if ($canManage)
            <section class="h-fit rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ $editing ? __('masterdata::ui.reference.edit', ['code' => $definition->identifier($editing)]) : __('masterdata::ui.reference.add') }}</h2>
                <form method="POST" action="{{ $formAction }}" class="mt-4 space-y-4">
                    @csrf
                    @if ($editing) @method('PUT') @endif
                    @foreach ($fields as $name => $f)
                        @continue($editing && ($f['create_only'] ?? false))
                        @php $value = old($name, $current[$name] ?? ($f['default'] ?? null)); @endphp
                        @if (($f['type'] ?? 'text') === 'checkbox')
                            <input type="hidden" name="{{ $name }}" value="0">
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="{{ $name }}" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old($name, $editing ? ($current[$name] ?? false) : true))> {{ __("masterdata::ui.reference.fields.{$name}") }}</label>
                        @elseif (($f['type'] ?? 'text') === 'select')
                            @php $options = value($f['options'] ?? []); @endphp
                            <div>
                                <label for="f-{{ $name }}" class="mb-1 block text-sm font-medium text-slate-700">{{ __("masterdata::ui.reference.fields.{$name}") }}</label>
                                <select id="f-{{ $name }}" name="{{ $name }}" class="{{ $select }}">
                                    @unless ($f['required'] ?? false) <option value="">—</option> @endunless
                                    @foreach ($options as $optValue => $label) <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $label }}</option> @endforeach
                                </select>
                                @error($name) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @else
                            <x-core::input :name="$name" :type="($f['type'] ?? 'text') === 'number' ? 'number' : 'text'" :label="__('masterdata::ui.reference.fields.'.$name)" :value="$value" :required="$f['required'] ?? false" :min="$f['min'] ?? null" :max="$f['max'] ?? null" />
                        @endif
                    @endforeach
                    <div class="flex gap-3">
                        <x-core::button type="submit">{{ __('masterdata::ui.common.save') }}</x-core::button>
                        @if ($editing) <a href="{{ route('masterdata.reference.index', $key) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('masterdata::ui.common.cancel') }}</a> @endif
                    </div>
                </form>
            </section>
        @endif
    </div>
</x-core::layouts.app>
