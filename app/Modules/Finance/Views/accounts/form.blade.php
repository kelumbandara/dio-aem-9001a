@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $editing = $account !== null;
@endphp
<x-core::layouts.app :title="$editing ? __('finance::ui.accounts.edit_title', ['code' => $account->code]) : __('finance::ui.accounts.new')">
    <form method="POST" action="{{ $editing ? route('finance.accounts.update', $account) : route('finance.accounts.store') }}" class="max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $account->version }}"> @endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-core::input name="code" :label="__('finance::ui.common.code')" :value="old('code', $account?->code)" required maxlength="12" />
            <x-core::input name="name" :label="__('finance::ui.common.name')" :value="old('name', $account?->name)" required maxlength="150" />
            <div>
                <label for="f-class" class="mb-1 block text-sm font-medium text-slate-700">{{ __('finance::ui.accounts.class') }}</label>
                <select id="f-class" name="class" required class="{{ $field }}">
                    @foreach (App\Modules\Finance\Models\Account::CLASSES as $c) <option value="{{ $c }}" @selected(old('class', $account?->class) === $c)>{{ __("finance::ui.classes.{$c}") }}</option> @endforeach
                </select>
                @error('class') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="f-parent" class="mb-1 block text-sm font-medium text-slate-700">{{ __('finance::ui.accounts.group') }}</label>
                <select id="f-parent" name="parent_id" class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($groups as $g) <option value="{{ $g->id }}" @selected((int) old('parent_id', $account?->parent_id) === $g->id)>{{ $g->code }} — {{ $g->name }} ({{ __("finance::ui.classes.{$g->class}") }})</option> @endforeach
                </select>
                @error('parent') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-core::input name="notes" :label="__('finance::ui.accounts.notes')" :value="old('notes', $account?->notes)" maxlength="255" />
        </div>
        <fieldset class="space-y-2 text-sm">
            <input type="hidden" name="is_group" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="is_group" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old('is_group', $account?->is_group ?? false))> {{ __('finance::ui.accounts.is_group') }}</label>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old('is_active', $account?->is_active ?? true))> {{ __('finance::ui.common.active') }}</label>
        </fieldset>
        <p class="text-xs text-slate-500">{{ __('finance::ui.accounts.locked_note') }}</p>
        <div class="flex gap-3 pt-2">
            <x-core::button type="submit">{{ __('finance::ui.common.save') }}</x-core::button>
            <a href="{{ route('finance.accounts.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('finance::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
