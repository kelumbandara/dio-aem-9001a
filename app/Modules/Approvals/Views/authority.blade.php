@php $select = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600'; @endphp
<x-core::layouts.app :title="__('approvals::ui.authority.title')">
    <p class="mb-6 max-w-2xl text-sm text-slate-600">{{ __('approvals::ui.authority.intro') }}</p>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('approvals::ui.authority.add') }}</h2>
            <form method="POST" action="{{ route('approvals.authority.store') }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="holder" class="mb-1 block text-sm font-medium text-slate-700">{{ __('approvals::ui.authority.holder') }}</label>
                    <select id="holder" name="holder" required class="{{ $select }}">
                        <option value="">—</option>
                        <optgroup label="{{ __('approvals::ui.authority.roles') }}">
                            @foreach ($roles as $role) <option value="role:{{ $role }}" @selected(old('holder') === "role:{$role}")>{{ $role }}</option> @endforeach
                        </optgroup>
                        <optgroup label="{{ __('approvals::ui.authority.users') }}">
                            @foreach ($userChoices as $id => $name) <option value="user:{{ $id }}" @selected(old('holder') === "user:{$id}")>{{ $name }}</option> @endforeach
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label for="action_key" class="mb-1 block text-sm font-medium text-slate-700">{{ __('approvals::ui.policies.action') }}</label>
                    <select id="action_key" name="action_key" required class="{{ $select }}">
                        <option value="*" @selected(old('action_key', '*') === '*')>{{ __('approvals::ui.authority.every_action') }}</option>
                        @foreach ($actions as $key) <option value="{{ $key }}" @selected(old('action_key') === $key)>{{ $key }}</option> @endforeach
                    </select>
                </div>
                <x-core::input name="max_amount" inputmode="decimal" :label="__('approvals::ui.authority.max_amount')" :hint="__('approvals::ui.authority.max_hint')" :value="old('max_amount')" />
                <x-core::input name="note" :label="__('approvals::ui.authority.note')" :value="old('note')" maxlength="255" />
                <x-core::button type="submit">{{ __('approvals::ui.authority.add') }}</x-core::button>
            </form>
        </section>

        <div class="xl:col-span-2">
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.authority.holder') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('approvals::ui.policies.action') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('approvals::ui.authority.max_amount') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('core::ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row->role_name ? __('approvals::ui.show.role', ['role' => $row->role_name]) : ($names[$row->user_id] ?? '') }}@if ($row->note)<div class="text-xs text-slate-500">{{ $row->note }}</div>@endif</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $row->action_key === '*' ? __('approvals::ui.authority.every_action') : $row->action_key }}</td>
                            <td class="px-4 py-3 text-end">@if ($row->max_amount === null) {{ __('approvals::ui.policies.no_limit') }} @else <x-core::money :value="$row->max_amount" /> @endif</td>
                            <td class="px-4 py-3 text-end">
                                <form method="POST" action="{{ route('approvals.authority.destroy', $row) }}">@csrf @method('DELETE')<button type="submit" class="text-sm text-red-600 hover:underline">{{ __('approvals::ui.authority.remove') }}</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ __('approvals::ui.authority.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </x-core::table>
        </div>
    </div>
</x-core::layouts.app>
