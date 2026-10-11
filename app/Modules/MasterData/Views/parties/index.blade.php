<x-core::layouts.app :title="__('masterdata::ui.parties.'.$slug.'.title')">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="q" class="mb-1 block text-xs font-medium text-slate-600">{{ __('masterdata::ui.common.search') }}</label>
                <input id="q" name="q" value="{{ $q }}" placeholder="{{ __('masterdata::ui.parties.search_hint') }}" class="block w-64 rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
            </div>
            <div>
                <label for="status" class="mb-1 block text-xs font-medium text-slate-600">{{ __('masterdata::ui.common.show') }}</label>
                <select id="status" name="status" class="block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                    @foreach (['active', 'inactive', 'all'] as $s) <option value="{{ $s }}" @selected($status === $s)>{{ __("masterdata::ui.common.status_{$s}") }}</option> @endforeach
                </select>
            </div>
            <x-core::button type="submit" variant="secondary">{{ __('masterdata::ui.common.filter') }}</x-core::button>
        </form>
        @can('create', $modelClass)
            <a href="{{ route($slug.'.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('masterdata::ui.parties.'.$slug.'.new') }}</a>
        @endcan
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.common.code') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.common.name') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.parties.tax_id') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.parties.phone') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.parties.email') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs"><a href="{{ route($slug.'.show', $row) }}" class="text-indigo-600 hover:underline">{{ $row->code }}</a></td>
                    <td class="px-4 py-3">{{ $row->name }}</td>
                    <td class="px-4 py-3">{{ $row->tax_id ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $row->phone ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $row->email ?: '—' }}</td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$row->is_active ? 'success' : 'neutral'">{{ __($row->is_active ? 'masterdata::ui.common.active' : 'masterdata::ui.common.inactive') }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ __('masterdata::ui.parties.'.$slug.'.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-core::layouts.app>
