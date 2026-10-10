@php $tz = config('core.display_timezone'); @endphp
<x-core::layouts.app :title="__('approvals::ui.delegations.title')">
    <p class="mb-6 max-w-2xl text-sm text-slate-600">{{ __('approvals::ui.delegations.intro', ['days' => $maxDays]) }}</p>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-1">
            <h2 class="text-base font-semibold text-slate-900">{{ __('approvals::ui.delegations.new') }}</h2>
            <form method="POST" action="{{ route('approvals.delegations.store') }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="delegate_id" class="mb-1 block text-sm font-medium text-slate-700">{{ __('approvals::ui.delegations.delegate') }}</label>
                    <select id="delegate_id" name="delegate_id" required class="block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                        <option value="">—</option>
                        @foreach ($candidates as $id => $name)
                            <option value="{{ $id }}" @selected((string) old('delegate_id') === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-core::input name="starts_at" type="datetime-local" :label="__('approvals::ui.delegations.from')" :value="old('starts_at', now($tz)->format('Y-m-d\TH:i'))" required />
                <x-core::input name="ends_at" type="datetime-local" :label="__('approvals::ui.delegations.until')" :value="old('ends_at', now($tz)->addDays(7)->format('Y-m-d\TH:i'))" required />
                <div>
                    <label for="action_key" class="mb-1 block text-sm font-medium text-slate-700">{{ __('approvals::ui.delegations.scope') }}</label>
                    <select id="action_key" name="action_key" class="block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                        <option value="">{{ __('approvals::ui.delegations.all_actions') }}</option>
                        @foreach ($actions as $key)
                            <option value="{{ $key }}" @selected(old('action_key') === $key)>{{ $key }}</option>
                        @endforeach
                    </select>
                </div>
                <x-core::input name="reason" :label="__('approvals::ui.delegations.reason')" :value="old('reason')" maxlength="255" />
                <p class="text-xs text-slate-500">{{ __('approvals::ui.delegations.note') }}</p>
                <x-core::button type="submit">{{ __('approvals::ui.delegations.create') }}</x-core::button>
            </form>
        </section>

        <div class="xl:col-span-2">
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.delegations.who') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.delegations.period') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.col.status') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('core::ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($delegations as $d)
                        @php
                            $state = $d->revoked_at ? 'revoked' : ($d->ends_at->lte(now()) ? 'ended' : ($d->starts_at->gt(now()) ? 'scheduled' : 'active'));
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                @if ($d->delegator_id === $me)
                                    {{ __('approvals::ui.delegations.to', ['name' => $names[$d->delegate_id] ?? '']) }}
                                @else
                                    {{ __('approvals::ui.delegations.from_person', ['name' => $names[$d->delegator_id] ?? '']) }}
                                @endif
                                <div class="text-xs text-slate-500">{{ $d->action_key ?? __('approvals::ui.delegations.all_actions') }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600"><x-core::datetime :value="$d->starts_at" /> → <x-core::datetime :value="$d->ends_at" /></td>
                            <td class="px-4 py-3"><x-core::status-badge :tone="$state === 'active' ? 'success' : 'neutral'">{{ __('approvals::ui.delegations.state.'.$state) }}</x-core::status-badge></td>
                            <td class="px-4 py-3 text-end">
                                @if ($d->delegator_id === $me && in_array($state, ['active', 'scheduled'], true))
                                    <form method="POST" action="{{ route('approvals.delegations.destroy', $d) }}">@csrf @method('DELETE')<x-core::button type="submit" variant="secondary" size="sm">{{ __('approvals::ui.delegations.revoke') }}</x-core::button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ __('approvals::ui.delegations.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </x-core::table>
        </div>
    </div>
</x-core::layouts.app>
