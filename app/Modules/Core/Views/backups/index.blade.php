<x-core::layouts.app :title="__('core::ui.backups.title')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('core::ui.backups.subtitle', ['count' => $keep]) }}</p>

    <x-core::alert type="warning" class="mb-6 max-w-3xl">{{ __('core::ui.backups.warning') }}</x-core::alert>

    <form method="POST" action="{{ route('backups.store') }}" class="mb-6">
        @csrf
        <x-core::button type="submit">{{ __('core::ui.backups.make_now') }}</x-core::button>
        <span class="ms-3 text-sm text-slate-500">{{ __('core::ui.backups.weekly') }}</span>
    </form>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.backups.made_at') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.backups.size') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium lg:table-cell">{{ __('core::ui.backups.checksum') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('core::ui.common.actions') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($backups as $backup)
                <tr>
                    <td class="whitespace-nowrap px-4 py-3">
                        <x-core::datetime :value="$backup['made_at']" />
                        <div class="font-mono text-xs text-slate-500">{{ $backup['name'] }}</div>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ \Illuminate\Support\Number::fileSize($backup['bytes'], 1) }}</td>
                    <td class="hidden px-4 py-3 font-mono text-xs text-slate-500 lg:table-cell" title="SHA-256">{{ $backup['sha256'] ? substr($backup['sha256'], 0, 16).'…' : '—' }}</td>
                    <td class="px-4 py-3 text-end">
                        <a href="{{ route('backups.download', $backup['name']) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ __('core::ui.backups.download') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">{{ __('core::ui.backups.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
</x-core::layouts.app>
