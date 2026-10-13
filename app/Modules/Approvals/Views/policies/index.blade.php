<x-core::layouts.app :title="__('approvals::ui.policies.title')">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-2xl text-sm text-slate-600">{{ __('approvals::ui.policies.intro') }}</p>
        <a href="{{ route('approvals.policies.create') }}"><x-core::button>{{ __('approvals::ui.policies.new') }}</x-core::button></a>
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.policies.name') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium md:table-cell">{{ __('approvals::ui.policies.when') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium lg:table-cell">{{ __('approvals::ui.policies.steps') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.col.status') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('core::ui.common.actions') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($policies as $p)
                <tr>
                    <td class="px-4 py-3"><div class="font-medium text-slate-900">{{ $p->name }}</div><div class="font-mono text-xs text-slate-500">{{ $p->action_key }}</div></td>
                    <td class="hidden px-4 py-3 text-slate-600 md:table-cell">
                        @if ($p->min_amount !== null || $p->max_amount !== null)
                            <x-core::money :value="$p->min_amount ?? '0'" /> – @if ($p->max_amount !== null)<x-core::money :value="$p->max_amount" />@else {{ __('approvals::ui.policies.no_limit') }} @endif
                        @else {{ __('approvals::ui.policies.any_amount') }} @endif
                        @if ($p->risk_levels) · {{ implode(', ', $p->risk_levels) }} @endif
                    </td>
                    <td class="hidden px-4 py-3 text-slate-600 lg:table-cell">
                        @foreach ($p->steps->groupBy('step_order') as $order => $group)
                            <div>{{ $order }}. {{ $group->map(fn ($s) => $s->approver_type === 'role' ? $s->approver_role : '#'.$s->approver_user_id)->implode(' + ') }}</div>
                        @endforeach
                    </td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$p->is_active ? 'success' : 'neutral'">{{ $p->is_active ? __('core::ui.common.active') : __('core::ui.common.inactive') }}</x-core::status-badge></td>
                    <td class="px-4 py-3 text-end">
                        <a class="text-sm text-indigo-700 hover:underline" href="{{ route('approvals.policies.edit', $p) }}">{{ __('approvals::ui.policies.edit') }}</a>
                        <form method="POST" action="{{ route('approvals.policies.destroy', $p) }}" class="ms-3 inline" x-on:submit="if (! confirm(@js(__('approvals::ui.policies.confirm_delete')))) $event.preventDefault()">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('approvals::ui.policies.delete') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('approvals::ui.policies.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
</x-core::layouts.app>
