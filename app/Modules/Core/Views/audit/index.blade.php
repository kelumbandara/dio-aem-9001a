<x-core::layouts.app :title="__('core::ui.audit.title')">
    <p class="mb-6 max-w-2xl text-sm text-slate-600">{{ __('core::ui.audit.subtitle') }}</p>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.common.time') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.common.user') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.common.event') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium md:table-cell">{{ __('core::ui.common.subject') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium lg:table-cell">{{ __('core::ui.common.channel') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap px-4 py-3 text-slate-600"><x-core::datetime :value="$entry->occurred_at" /></td>
                    <td class="px-4 py-3">{{ $entry->user?->name ?? __('core::ui.common.system') }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $entry->event }}</td>
                    <td class="hidden px-4 py-3 text-slate-600 md:table-cell">{{ $entry->auditable_type ? class_basename($entry->auditable_type).' #'.$entry->auditable_id : '—' }}</td>
                    <td class="hidden px-4 py-3 text-slate-600 lg:table-cell">{{ $entry->channel }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('core::ui.common.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>

    <div class="mt-4">{{ $entries->links() }}</div>
</x-core::layouts.app>
