<x-core::layouts.app :title="__('finance::ui.accounts.title')">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex gap-2 text-sm">
            @foreach (['active', 'all'] as $s)
                <a href="{{ route('finance.accounts.index', ['show' => $s]) }}" class="rounded-lg px-3 py-1.5 {{ $show === $s ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' }}">{{ __("finance::ui.accounts.show_{$s}") }}</a>
            @endforeach
        </nav>
        @can('finance.account.manage')
            <a href="{{ route('finance.accounts.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('finance::ui.accounts.new') }}</a>
        @endcan
    </div>

    @foreach (['asset', 'liability', 'equity', 'income', 'expense'] as $class)
        @continue(empty($classes[$class]))
        <h2 class="mb-2 mt-6 text-base font-semibold text-slate-900">{{ __("finance::ui.classes.{$class}") }}</h2>
        @foreach ($classes[$class] as $block)
            @continue($block['rows']->isEmpty())
            <x-core::table class="mb-4">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ $block['group']->code }} — {{ $block['group']->name }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.accounts.balance') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($block['rows'] as $row)
                        @php $a = $row['account']; @endphp
                        <tr class="{{ $a->is_active ? '' : 'text-slate-400' }}">
                            <td class="px-4 py-2"><a href="{{ route('finance.accounts.ledger', $a) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $a->code }}</a> {{ $a->name }}@unless ($a->is_active) <x-core::status-badge>{{ __('finance::ui.common.inactive') }}</x-core::status-badge>@endunless</td>
                            <td class="px-4 py-2 text-end"><x-core::money :value="$row['balance']" /></td>
                            <td class="px-4 py-2 text-end">@can('finance.account.manage')<a href="{{ route('finance.accounts.edit', $a) }}" class="text-sm text-indigo-600 hover:underline">{{ __('finance::ui.common.edit') }}</a>@endcan</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-core::table>
        @endforeach
    @endforeach
    <p class="mt-2 text-xs text-slate-500">{{ __('finance::ui.accounts.balance_note') }}</p>
</x-core::layouts.app>
