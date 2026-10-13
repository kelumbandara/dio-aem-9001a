<x-core::layouts.app :title="__('communications::ui.menu.templates')">
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('communications::ui.templates.intro') }}</p>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.template') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.channel') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.templates.language') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.templates.flags') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($templates as $t)
                <tr>
                    <td class="px-4 py-3 font-medium">{{ __('communications::ui.template.'.$t->key) }}<div class="text-xs font-normal text-slate-500">{{ $t->key }}</div></td>
                    <td class="px-4 py-3">{{ __('communications::ui.channel.'.$t->channel) }}</td>
                    <td class="px-4 py-3">{{ $t->locale }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        {{ $t->is_active ? __('communications::ui.templates.active') : __('communications::ui.templates.off') }}
                        @if ($t->sensitive) · {{ __('communications::ui.messages.needs_approval') }} @endif
                        @if ($t->channel === 'whatsapp') · {{ $t->approved ? __('communications::ui.templates.approved') : __('communications::ui.templates.not_approved') }} @endif
                    </td>
                    <td class="px-4 py-3 text-end">@if ($canManage) <a class="text-indigo-600 hover:underline" href="{{ route('comm.templates.edit', $t->public_id) }}">{{ __('communications::ui.templates.edit') }}</a> @endif</td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>
</x-core::layouts.app>
