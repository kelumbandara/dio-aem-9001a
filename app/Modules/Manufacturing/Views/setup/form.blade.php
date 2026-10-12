@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $editing = $row !== null;
    $v = fn ($key, $default = null) => old($key, $editing ? ($row[$key] ?? $default) : $default);
    $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $dayString = $v('days', '1111110');
@endphp
<x-core::layouts.app :title="($editing ? __('manufacturing::ui.common.edit').' · ' : __('manufacturing::ui.setup.new').' · ').__('manufacturing::ui.setup.'.$entity.'.one')">
    <form method="POST" action="{{ $editing ? route('manufacturing.setup.update', [$entity, $row['id']]) : route('manufacturing.setup.store', $entity) }}" class="max-w-3xl space-y-6">
        @csrf
        @if ($editing) @method('PUT') @endif
        <section class="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2">
            <x-core::input name="code" :label="__('manufacturing::ui.common.code')" :value="$v('code')" maxlength="30" required />
            <x-core::input name="name" :label="__('manufacturing::ui.common.name')" :value="$v('name')" maxlength="150" required />

            @if ($entity === 'work-centers')
                <x-core::input name="capacity_hours_per_day" inputmode="decimal" :label="__('manufacturing::ui.setup.capacity')" :value="$v('capacity_hours_per_day', '8')" required />
                <x-core::input name="hourly_rate" inputmode="decimal" :label="__('manufacturing::ui.setup.rate')" :value="$v('hourly_rate', '0')" :hint="__('manufacturing::ui.setup.rate_hint')" required />
            @endif

            @if ($entity === 'machines')
                <div>
                    <label for="f-work_center" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.setup.work-centers.one') }}</label>
                    <select id="f-work_center" name="work_center" required class="{{ $field }}">
                        <option value="">—</option>
                        @foreach ($options['workCenters'] as $c) <option value="{{ $c }}" @selected($v('work_center') === $c)>{{ $c }}</option> @endforeach
                    </select>
                    @error('work_center') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-production_line" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.setup.lines.one') }}</label>
                    <select id="f-production_line" name="production_line" class="{{ $field }}">
                        <option value="">—</option>
                        @foreach ($options['lines'] as $l) <option value="{{ $l }}" @selected($v('production_line') === $l)>{{ $l }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-status" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.common.status') }}</label>
                    <select id="f-status" name="status" required class="{{ $field }}">
                        @foreach (\App\Modules\Manufacturing\Models\Machine::STATUSES as $s) <option value="{{ $s }}" @selected($v('status', 'available') === $s)>{{ __("manufacturing::ui.status.{$s}") }}</option> @endforeach
                    </select>
                </div>
            @endif

            @if ($entity === 'downtime-reasons')
                <div>
                    <label for="f-category" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.setup.category') }}</label>
                    <select id="f-category" name="category" required class="{{ $field }}">
                        @foreach (\App\Modules\Manufacturing\Models\DowntimeReason::CATEGORIES as $c) <option value="{{ $c }}" @selected($v('category', 'breakdown') === $c)>{{ __("manufacturing::ui.setup.categories.{$c}") }}</option> @endforeach
                    </select>
                    @error('category') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($entity === 'shifts')
                <x-core::input name="start_time" type="time" :label="__('manufacturing::ui.setup.starts')" :value="$v('start_time', '08:00')" required />
                <x-core::input name="end_time" type="time" :label="__('manufacturing::ui.setup.ends')" :value="$v('end_time', '17:00')" :hint="__('manufacturing::ui.setup.ends_hint')" required />
                <fieldset class="sm:col-span-2">
                    <legend class="mb-1 text-sm font-medium text-slate-700">{{ __('manufacturing::ui.setup.days') }}</legend>
                    <div class="flex flex-wrap gap-4">
                        @foreach ($days as $i => $d)
                            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="day[]" value="{{ $i + 1 }}" @checked(old('day') !== null ? in_array((string) ($i + 1), (array) old('day'), true) : ($dayString[$i] ?? '0') === '1')> {{ __("manufacturing::ui.days.{$d}") }}</label>
                        @endforeach
                    </div>
                    @error('days') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </fieldset>
            @endif

            @if (in_array($entity, ['work-centers', 'lines'], true))
                <div class="sm:col-span-2"><x-core::input name="description" :label="__('manufacturing::ui.common.notes')" :value="$v('description')" /></div>
            @elseif ($entity === 'machines')
                <div class="sm:col-span-2"><x-core::input name="notes" :label="__('manufacturing::ui.common.notes')" :value="$v('notes')" /></div>
            @endif

            @if ($entity !== 'machines')
                <label class="inline-flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing ? $row['is_active'] : true))> {{ __('manufacturing::ui.setup.is_active') }}</label>
            @endif
        </section>
        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('manufacturing::ui.common.save') }}</x-core::button>
            <a href="{{ route('manufacturing.setup.list', $entity) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('manufacturing::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
