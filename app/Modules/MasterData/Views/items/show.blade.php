<x-core::layouts.app :title="$item->code.' — '.$item->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-core::status-badge :tone="$item->is_active ? 'success' : 'neutral'">{{ __($item->is_active ? 'masterdata::ui.common.active' : 'masterdata::ui.common.inactive') }}</x-core::status-badge>
        <div class="flex gap-2">
            <a href="{{ route('items.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('masterdata::ui.common.back') }}</a>
            @can('update', $item)
                <a href="{{ route('items.edit', $item) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('masterdata::ui.common.edit') }}</a>
            @endcan
        </div>
    </div>

    <section class="max-w-3xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            @foreach ([
                'category' => $item->category->code.' — '.$item->category->name,
                'type' => __("masterdata::ui.items.types.{$item->item_type}"),
                'base_uom' => $item->baseUom->code.' — '.$item->baseUom->name,
                'tracking' => __("masterdata::ui.items.tracking_{$item->tracking}"),
                'shelf_life' => $item->shelf_life_days ? __('masterdata::ui.items.days', ['count' => $item->shelf_life_days]) : '—',
                'barcode' => $item->barcode ?: '—',
                'tax_code' => $item->defaultTaxCode ? $item->defaultTaxCode->code : '—',
                'sale_purchase' => collect([$item->is_sellable ? __('masterdata::ui.items.is_sellable') : null, $item->is_purchasable ? __('masterdata::ui.items.is_purchasable') : null])->filter()->implode(', ') ?: '—',
            ] as $label => $value)
                <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __("masterdata::ui.items.{$label}") }}</dt><dd class="mt-0.5 text-slate-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
        @if ($item->description) <p class="mt-4 whitespace-pre-line text-sm text-slate-700">{{ $item->description }}</p> @endif
    </section>

    <section class="mt-6 max-w-3xl">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('masterdata::ui.items.alt_units') }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.items.unit') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('masterdata::ui.items.factor_in', ['uom' => $item->baseUom->code]) }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.items.used_for') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($item->uoms as $u)
                    <tr>
                        <td class="px-4 py-3">{{ $u->uom->code }}</td>
                        <td class="px-4 py-3 text-end">{{ rtrim(rtrim($u->factor, '0'), '.') }}</td>
                        <td class="px-4 py-3">{{ collect([$u->for_purchase ? __('masterdata::ui.items.for_purchase') : null, $u->for_sale ? __('masterdata::ui.items.for_sale') : null])->filter()->implode(', ') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">{{ __('masterdata::ui.items.no_alt_units') }}</td></tr>
                @endforelse
            </tbody>
        </x-core::table>
    </section>
</x-core::layouts.app>
