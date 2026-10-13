<x-core::layouts.app :title="__('core::ui.users.title')">
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex gap-2">
            <input name="q" value="{{ $q }}" placeholder="{{ __('core::ui.users.search') }}" aria-label="{{ __('core::ui.users.search') }}" class="block w-64 rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
            <x-core::button type="submit" variant="secondary">{{ __('core::ui.users.search_button') }}</x-core::button>
        </form>
        @can('core.user.manage')
            <a href="{{ route('users.create') }}"><x-core::button type="button">{{ __('core::ui.users.add') }}</x-core::button></a>
        @endcan
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.users.name') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium md:table-cell">{{ __('core::ui.users.email') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.users.roles') }}</th>
                <th class="hidden px-4 py-3 text-start font-medium lg:table-cell">{{ __('core::ui.users.last_login') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('core::ui.users.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($users as $u)
                <tr>
                    <td class="px-4 py-3"><a class="font-medium text-indigo-700 hover:underline" href="{{ route('users.edit', $u) }}">{{ $u->name }}</a></td>
                    <td class="hidden px-4 py-3 text-slate-600 md:table-cell">{{ $u->email }}</td>
                    <td class="px-4 py-3 text-slate-600">{{ $u->roles->pluck('name')->join(', ') ?: '—' }}</td>
                    <td class="hidden px-4 py-3 text-slate-600 lg:table-cell">@if ($u->last_login_at) <x-core::datetime :value="$u->last_login_at" /> @else — @endif</td>
                    <td class="px-4 py-3">{{ $u->is_active ? __('core::ui.users.active') : __('core::ui.users.inactive') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('core::ui.common.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $users->links() }}</div>
</x-core::layouts.app>
