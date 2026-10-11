<x-core::layouts.app :title="$list->code.' — '.$list->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <x-core::status-badge :tone="$list->is_active ? 'success' : 'neutral'">{{ __($list->is_active ? 'masterdata::ui.common.active' : 'masterdata::ui.common.inactive') }}</x-core::status-badge>
            <span>{{ __("masterdata::ui.price_lists.types.{$list->type}") }} · {{ $list->currency->code }} · {{ __($list->tax_inclusive ? 'masterdata::ui.price_lists.incl_tax' : 'masterdata::ui.price_lists.excl_tax') }}</span>
            @if ($list->is_default) <x-core::status-badge tone="info">{{ __('masterdata::ui.parties.default') }}</x-core::status-badge> @endif
        </div>
        <div class="flex gap-2">
            <a href="{{ route('price-lists.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('masterdata::ui.common.back') }}</a>
            @can('update', $list)
                <a href="{{ route('price-lists.edit', $list) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('masterdata::ui.common.edit') }}</a>
            @endcan
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.price_lists.item') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.items.unit') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('masterdata::ui.price_lists.min_qty') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('masterdata::ui.price_lists.unit_price') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($prices as $p)
                        <tr>
                            <td class="px-4 py-3"><span class="font-mono text-xs">{{ $p->item->code }}</span> {{ $p->item->name }}</td>
                            <td class="px-4 py-3">{{ $p->uom->code }}</td>
                            <td class="px-4 py-3 text-end">{{ rtrim(rtrim($p->min_qty, '0'), '.') ?: '0' }}</td>
                            <td class="px-4 py-3 text-end">{{ rtrim(rtrim($p->unit_price, '0'), '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ __('masterdata::ui.price_lists.no_prices') }}</td></tr>
                    @endforelse
                </tbody>
            </x-core::table>
            <div class="mt-4">{{ $prices->links() }}</div>
        </div>

        @can('update', $list)
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.prices.change') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('masterdata::ui.prices.format') }}</p>
                <form method="POST" action="{{ route('price-lists.prices.change', $list) }}" class="mt-3 space-y-3">
                    @csrf
                    <textarea name="lines" rows="8" required class="block w-full rounded-lg border-0 px-3 py-2 font-mono text-xs shadow-sm ring-1 ring-inset {{ $errors->has('lines') ? 'ring-red-400' : 'ring-slate-300' }} focus:ring-2 focus:ring-indigo-600" placeholder="ITM-00001,125.50&#10;ITM-00002,980,BOX&#10;ITM-00002,950,BOX,10&#10;ITM-00003,">{{ old('lines') }}</textarea>
                    @error('lines') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <x-core::button type="submit">{{ __('masterdata::ui.prices.apply') }}</x-core::button>
                </form>
            </section>
        @endcan
    </div>
</x-core::layouts.app>
