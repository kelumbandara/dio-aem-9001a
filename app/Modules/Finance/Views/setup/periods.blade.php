<x-core::layouts.app :title="__('finance::ui.periods.title')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('finance::ui.periods.intro') }}</p>

    @foreach ($years as $year)
        <section class="mb-6">
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('finance::ui.periods.year', ['name' => $year->name]) }} <span class="text-sm font-normal text-slate-500">{{ $year->starts_on->toDateString() }} → {{ $year->ends_on->toDateString() }}</span></h2>
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.periods.period') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.from') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.to') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($year->periods as $p)
                        <tr>
                            <td class="px-4 py-2">{{ $p->name }}</td>
                            <td class="px-4 py-2">{{ $p->starts_on->toDateString() }}</td>
                            <td class="px-4 py-2">{{ $p->ends_on->toDateString() }}</td>
                            <td class="px-4 py-2"><x-core::status-badge :tone="['open' => 'success', 'closed' => 'warning', 'locked' => 'neutral'][$p->status]">{{ __("finance::ui.periods.status.{$p->status}") }}</x-core::status-badge></td>
                            <td class="whitespace-nowrap px-4 py-2 text-end text-sm">
                                @php $acts = ['open' => ['close'], 'closed' => ['reopen', 'lock'], 'locked' => []][$p->status]; @endphp
                                @foreach ($acts as $act)
                                    @if (($act === 'lock' && $canLock) || ($act !== 'lock' && $canManage))
                                        <form method="POST" action="{{ route('finance.periods.change', [$p, $act]) }}" class="inline" @if ($act === 'lock') x-on:submit="if (! confirm(@js(__('finance::ui.periods.lock_confirm')))) $event.preventDefault()" @endif>@csrf<button type="submit" class="ms-3 {{ $act === 'lock' ? 'text-red-600' : 'text-indigo-600' }} hover:underline">{{ __("finance::ui.periods.{$act}") }}</button></form>
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-core::table>
        </section>
    @endforeach

    @if ($canManage)
        <form method="POST" action="{{ route('finance.periods.years.store') }}" class="flex max-w-md items-end gap-2 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <x-core::input name="start_year" type="number" :label="__('finance::ui.periods.new_year')" :value="old('start_year', $nextYear)" required />
            <x-core::button type="submit">{{ __('finance::ui.periods.create_year') }}</x-core::button>
        </form>
    @endif
</x-core::layouts.app>
