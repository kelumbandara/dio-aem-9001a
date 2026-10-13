@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('communications::ui.messages.new')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('communications::ui.messages.new_intro') }}</p>

    <form method="GET" class="mb-6 flex flex-wrap items-end gap-2">
        <div><label for="channel" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.messages.channel') }}</label>
            <select id="channel" name="channel" class="{{ $field }}">@foreach (['email', 'whatsapp'] as $c) <option value="{{ $c }}" @selected($channel === $c)>{{ __('communications::ui.channel.'.$c) }}</option> @endforeach</select></div>
        <div><label for="template" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.messages.template') }}</label>
            <select id="template" name="template" class="{{ $field }}"><option value="">—</option>@foreach ($templates as $t) <option value="{{ $t->key }}" @selected($chosen?->key === $t->key)>{{ __('communications::ui.template.'.$t->key) }}{{ $t->sensitive ? ' ('.__('communications::ui.messages.needs_approval').')' : '' }}</option> @endforeach</select></div>
        <x-core::button type="submit" variant="secondary">{{ __('communications::ui.messages.next') }}</x-core::button>
    </form>

    @if ($chosen)
        <form method="POST" action="{{ route('comm.messages.store') }}" class="max-w-xl space-y-4">
            @csrf
            <input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="template" value="{{ $chosen->key }}">
            <div class="grid grid-cols-3 gap-3">
                <div><label for="party_type" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.messages.party_type') }}</label>
                    <select id="party_type" name="party_type" class="{{ $field }}"><option value="customer" @selected(old('party_type') === 'customer')>{{ __('communications::ui.party.customer') }}</option><option value="supplier" @selected(old('party_type') === 'supplier')>{{ __('communications::ui.party.supplier') }}</option></select></div>
                <div class="col-span-2"><label for="party" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.messages.party_code') }}</label>
                    <input id="party" name="party" value="{{ old('party') }}" class="{{ $field }}" required>@error('party') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror</div>
            </div>
            @foreach ($fields as $f)
                <div><label for="p_{{ $f }}" class="mb-1 block text-xs font-medium text-slate-600">{{ $f }}</label>
                    <input id="p_{{ $f }}" name="params[{{ $f }}]" value="{{ old('params.'.$f) }}" class="{{ $field }}" maxlength="300" required>@error('params.'.$f) <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror</div>
            @endforeach
            @error('address') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            @error('template') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            @error('form') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 whitespace-pre-line">{{ $chosen->body }}</p>
            <x-core::button type="submit">{{ __('communications::ui.messages.prepare') }}</x-core::button>
        </form>
    @endif
</x-core::layouts.app>
