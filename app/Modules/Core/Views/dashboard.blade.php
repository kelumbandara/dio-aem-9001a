<x-core::layouts.app :title="__('core::ui.dashboard.title')">
    <p class="mb-6 text-slate-600">{{ __('core::ui.dashboard.welcome', ['name' => auth()->user()->name]) }}</p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-core::kpi-card :label="__('core::ui.dashboard.users')" :value="number_format($stats['users'])" />
        <x-core::kpi-card :label="__('core::ui.dashboard.branches')" :value="number_format($stats['branches'])" />
        <x-core::kpi-card :label="__('core::ui.dashboard.departments')" :value="number_format($stats['departments'])" />
        <x-core::kpi-card :label="__('core::ui.dashboard.employees')" :value="number_format($stats['employees'])" />
    </div>

    <div class="mt-8 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="xl:col-span-2">
            @if ($recentActivity !== null)
                <h2 class="mb-3 text-base font-semibold text-slate-900">{{ __('core::ui.dashboard.recent_activity') }}</h2>
                <x-core::table>
                    <thead class="bg-slate-50 text-start text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.common.time') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.common.user') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.common.event') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($recentActivity as $entry)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><x-core::datetime :value="$entry->occurred_at" /></td>
                                <td class="px-4 py-3">{{ $entry->user?->name ?? __('core::ui.common.system') }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $entry->event }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">{{ __('core::ui.common.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </x-core::table>
            @endif
        </section>

        <section>
            <h2 class="mb-3 text-base font-semibold text-slate-900">{{ __('core::ui.dashboard.modules') }}</h2>
            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white shadow-sm">
                @foreach ($modules as $module)
                    <li class="flex items-center justify-between px-4 py-3 text-sm">
                        <span>{{ $module->name }}</span>
                        <x-core::status-badge :tone="$modulesEnabled[$module->key] ? 'success' : 'neutral'">
                            {{ $modulesEnabled[$module->key] ? __('core::ui.common.enabled') : __('core::ui.common.disabled') }}
                        </x-core::status-badge>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</x-core::layouts.app>
