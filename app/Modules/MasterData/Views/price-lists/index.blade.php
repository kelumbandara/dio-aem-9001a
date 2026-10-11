<x-core::layouts.app :title="__('masterdata::ui.price_lists.title')">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex gap-2 text-sm" aria-label="{{ __('masterdata::ui.price_lists.type') }}">
            @foreach ([null => 'all', 'sales' => 'sales', 'purchase' => 'purchase'] as $value => $label)
                <a href="{{ route('price-lists.index', $value ? ['type' => $value] : []) }}" class="rounded-lg px-3 py-1.5 {{ $type === $value ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' }}">{{ __("masterdata::ui.price_lists.types.{$label}") }}</a>
            @endforeach
        </nav>
        @can('create', App\Modules\MasterData\Models\PriceList::class)
            <a href="{{ route('price-lists.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('masterdata::ui.price_lists.new') }}</a>
        @endcan
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.common.code') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.common.name') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.price_lists.type') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.parties.currency') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.price_lists.valid') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($lists as $l)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs"><a href="{{ route('price-lists.show', $l) }}" class="text-indigo-600 hover:underline">{{ $l->code }}</a></td>
                    <td class="px-4 py-3">{{ $l->name }}</td>
                    <td class="px-4 py-3">{{ __("masterdata::ui.price_lists.types.{$l->type}") }}@if ($l->is_default) <x-core::status-badge tone="info">{{ __('masterdata::ui.parties.default') }}</x-core::status-badge>@endif</td>
                    <td class="px-4 py-3">{{ $l->currency->code }}</td>
                    <td class="px-4 py-3">{{ $l->valid_from || $l->valid_to ? ($l->valid_from?->toDateString() ?? '…').' → '.($l->valid_to?->toDateString() ?? '…') : '—' }}</td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$l->is_active ? 'success' : 'neutral'">{{ __($l->is_active ? 'masterdata::ui.common.active' : 'masterdata::ui.common.inactive') }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('masterdata::ui.price_lists.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $lists->links() }}</div>
</x-core::layouts.app>
