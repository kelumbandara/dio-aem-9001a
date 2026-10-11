<x-core::layouts.app :title="__('inventory::ui.stock.title')">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="q" class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.common.search') }}</label>
                <input id="q" name="q" value="{{ $q }}" placeholder="{{ __('inventory::ui.stock.search_hint') }}" class="block w-64 rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
            </div>
            <div>
                <label for="warehouse" class="mb-1 block text-xs font-medium text-slate-600">{{ __('inventory::ui.common.warehouse') }}</label>
                <select id="warehouse" name="warehouse" class="block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                    <option value="">{{ __('inventory::ui.common.all') }}</option>
                    @foreach ($warehouses as $w) <option value="{{ $w->code }}" @selected($warehouse === $w->code)>{{ $w->code }} — {{ $w->name }}</option> @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="below" value="1" class="rounded border-slate-300 text-indigo-600" @checked($below)> {{ __('inventory::ui.stock.only_below') }}</label>
            <x-core::button type="submit" variant="secondary">{{ __('inventory::ui.common.filter') }}</x-core::button>
        </form>
        <div class="flex flex-wrap gap-2 text-sm">
            @foreach (['receipt' => 'inventory.receive', 'issue' => 'inventory.issue', 'transfer' => 'inventory.transfer', 'adjustment' => 'inventory.adjust'] as $type => $permission)
                @can($permission)
                    <a href="{{ route('inventory.documents.create', $type) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 font-medium text-white shadow-sm hover:bg-indigo-500">{{ __("inventory::ui.documents.new_{$type}") }}</a>
                @endcan
            @endforeach
        </div>
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.item') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.on_hand') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.reserved') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.available') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.avg_cost') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.value') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3"><a href="{{ route('inventory.stock.item', $r['item_code']) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $r['item_code'] }}</a> {{ $r['item_name'] }}</td>
                    <td class="px-4 py-3 text-end">{{ rtrim(rtrim($r['on_hand'], '0'), '.') }} {{ $r['uom'] }}</td>
                    <td class="px-4 py-3 text-end">{{ rtrim(rtrim($r['reserved'], '0'), '.') ?: '0' }}</td>
                    <td class="px-4 py-3 text-end">{{ rtrim(rtrim($r['available'], '0'), '.') ?: '0' }}</td>
                    <td class="px-4 py-3 text-end"><x-core::money :value="$r['average_cost']" /></td>
                    <td class="px-4 py-3 text-end"><x-core::money :value="$r['value']" /></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('inventory::ui.stock.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-core::layouts.app>
