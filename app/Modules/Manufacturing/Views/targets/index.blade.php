@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('manufacturing::ui.targets.title')">
    <div class="mb-4"><a href="{{ route('manufacturing.setup.index') }}" class="text-sm text-indigo-600 hover:underline">← {{ __('manufacturing::ui.setup.title') }}</a></div>
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('manufacturing::ui.targets.intro') }}</p>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.report.shift') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.item') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.targets.target') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.status') }}</th>
                @if ($canManage) <th class="px-4 py-3"></th> @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3">{{ $r['shift_name'] }} <span class="font-mono text-xs text-slate-500">{{ $r['shift'] }}</span></td>
                    <td class="px-4 py-3"><span class="font-mono text-xs">{{ $r['item'] }}</span> {{ $r['item_name'] }}</td>
                    @if ($canManage)
                        <form method="POST" action="{{ route('manufacturing.targets.save') }}" id="t-{{ $loop->index }}">@csrf
                            <input type="hidden" name="shift" value="{{ $r['shift'] }}"><input type="hidden" name="item" value="{{ $r['item'] }}"></form>
                        <td class="px-4 py-3 text-end"><input form="t-{{ $loop->index }}" name="target" value="{{ $r['target'] }}" inputmode="decimal" class="{{ $field }} w-28 text-end" aria-label="{{ __('manufacturing::ui.targets.target') }}"> {{ $r['uom'] }}</td>
                        <td class="px-4 py-3"><label class="inline-flex items-center gap-2 text-sm"><input form="t-{{ $loop->index }}" type="hidden" name="is_active" value="0"><input form="t-{{ $loop->index }}" type="checkbox" name="is_active" value="1" @checked($r['active'])> {{ __('manufacturing::ui.setup.is_active') }}</label></td>
                        <td class="px-4 py-3 text-end"><x-core::button form="t-{{ $loop->index }}" type="submit" variant="secondary" size="sm">{{ __('manufacturing::ui.common.save') }}</x-core::button></td>
                    @else
                        <td class="px-4 py-3 text-end">{{ $r['target'] }} {{ $r['uom'] }}</td>
                        <td class="px-4 py-3">{{ $r['active'] ? __('manufacturing::ui.status.active') : __('manufacturing::ui.status.inactive') }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('manufacturing::ui.targets.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>

    @if ($canManage)
        <form method="POST" action="{{ route('manufacturing.targets.save') }}" class="mt-6 grid max-w-4xl grid-cols-1 items-end gap-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            @csrf
            <div class="sm:col-span-4 text-sm font-medium text-slate-700">{{ __('manufacturing::ui.targets.add') }}</div>
            <div>
                <label for="t-shift" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.report.shift') }}</label>
                <select id="t-shift" name="shift" required class="{{ $field }}"><option value="">—</option>@foreach ($shifts as $s) <option value="{{ $s->code }}" @selected(old('shift') === $s->code)>{{ $s->name }}</option> @endforeach</select>
                @error('shift') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-item" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.common.item') }}</label>
                <input id="t-item" name="item" list="t-items" value="{{ old('item') }}" required autocomplete="off" class="{{ $field }}" placeholder="ITM-00001">
                <datalist id="t-items">@foreach ($products as $p) <option value="{{ $p['code'] }}">{{ $p['name'] }}</option> @endforeach</datalist>
                @error('item') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-target" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.targets.target') }}</label>
                <input id="t-target" name="target" value="{{ old('target') }}" inputmode="decimal" required class="{{ $field }}">
                @error('target') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-core::button type="submit">{{ __('manufacturing::ui.targets.set') }}</x-core::button>
        </form>
    @endif
</x-core::layouts.app>
