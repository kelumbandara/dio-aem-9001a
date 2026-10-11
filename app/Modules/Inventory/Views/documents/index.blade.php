@php $num = fn ($v) => rtrim(rtrim($v, '0'), '.') ?: '0'; @endphp
<x-core::layouts.app :title="__('inventory::ui.documents.title')">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="q" class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.common.search') }}</label>
                <input id="q" name="q" value="{{ $q }}" placeholder="{{ __('inventory::ui.documents.search_hint') }}" class="block w-56 rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
            </div>
            <div>
                <label for="type" class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.common.type') }}</label>
                <select id="type" name="type" class="block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                    <option value="">{{ __('inventory::ui.common.all') }}</option>
                    @foreach (App\Modules\Inventory\Models\StockDocument::TYPES as $t) <option value="{{ $t }}" @selected($type === $t)>{{ __("inventory::ui.documents.types.{$t}") }}</option> @endforeach
                </select>
            </div>
            <x-core::button type="submit" variant="secondary">{{ __('inventory::ui.common.filter') }}</x-core::button>
        </form>
        <div class="flex flex-wrap gap-2 text-sm">
            @foreach (['receipt' => 'inventory.receive', 'issue' => 'inventory.issue', 'transfer' => 'inventory.transfer', 'adjustment' => 'inventory.adjust'] as $t => $permission)
                @can($permission)
                    <a href="{{ route('inventory.documents.create', $t) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 font-medium text-white shadow-sm hover:bg-indigo-500">{{ __("inventory::ui.documents.new_{$t}") }}</a>
                @endcan
            @endforeach
        </div>
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.documents.document') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.type') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.date') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.documents.reason') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.value') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs"><a href="{{ route('inventory.documents.show', $r['id']) }}" class="text-indigo-600 hover:underline">{{ $r['document_no'] }}</a>@if ($r['reference'])<div class="font-sans text-slate-500">{{ $r['reference'] }}</div>@endif</td>
                    <td class="px-4 py-3">{{ __("inventory::ui.documents.types.{$r['type']}") }}</td>
                    <td class="px-4 py-3">{{ $r['posting_date'] }}</td>
                    <td class="px-4 py-3">{{ __("inventory::ui.documents.reasons.{$r['reason']}") }}</td>
                    <td class="px-4 py-3 text-end"><x-core::money :value="$r['total_value']" /></td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$r['status'] === 'posted' ? 'success' : 'neutral'">{{ __("inventory::ui.documents.status.{$r['status']}") }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('inventory::ui.documents.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $page->links() }}</div>
</x-core::layouts.app>
