<x-core::layouts.app :title="__('core::ui.modules.title')">
    <p class="mb-6 max-w-2xl text-sm text-slate-600">{{ __('core::ui.modules.subtitle') }}</p>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.modules.name') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium sm:table-cell">{{ __('core::ui.modules.depends_on') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.modules.status') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('core::ui.common.actions') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($modules as $module)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-900">{{ $module->name }}</div>
                        <div class="font-mono text-xs text-slate-500">{{ $module->key }}</div>
                    </td>
                    <td class="hidden px-4 py-3 text-slate-600 sm:table-cell">{{ $module->dependsOn ? implode(', ', $module->dependsOn) : '—' }}</td>
                    <td class="px-4 py-3">
                        <x-core::status-badge :tone="$enabled[$module->key] ? 'success' : 'neutral'">
                            {{ $enabled[$module->key] ? __('core::ui.common.enabled') : __('core::ui.common.disabled') }}
                        </x-core::status-badge>
                    </td>
                    <td class="px-4 py-3 text-end">
                        @if ($module->required)
                            <x-core::status-badge tone="info">{{ __('core::ui.common.required') }}</x-core::status-badge>
                        @elseif ($enabled[$module->key])
                            <x-core::button variant="secondary" size="sm" x-data @click="$dispatch('open-modal', 'disable-{{ $module->key }}')">{{ __('core::ui.common.disable') }}</x-core::button>
                            <x-core::modal :name="'disable-'.$module->key" :title="__('core::ui.modules.confirm_disable_title', ['module' => $module->name])">
                                {{ __('core::ui.modules.confirm_disable_body') }}
                                <x-slot:footer>
                                    <x-core::button variant="secondary" @click="open = false">{{ __('core::ui.common.cancel') }}</x-core::button>
                                    <form method="POST" action="{{ route('modules.disable', $module->key) }}">
                                        @csrf
                                        <x-core::button type="submit" variant="danger">{{ __('core::ui.common.disable') }}</x-core::button>
                                    </form>
                                </x-slot:footer>
                            </x-core::modal>
                        @else
                            <form method="POST" action="{{ route('modules.enable', $module->key) }}" class="inline">
                                @csrf
                                <x-core::button type="submit" size="sm">{{ __('core::ui.common.enable') }}</x-core::button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>
</x-core::layouts.app>
