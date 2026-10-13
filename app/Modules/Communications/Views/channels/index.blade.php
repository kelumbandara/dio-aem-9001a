@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('communications::ui.menu.channels')">
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('communications::ui.channels.intro') }}</p>

    <div class="max-w-xl space-y-6">
        <div class="space-y-2">
            @forelse ($identities as $i)
                <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <div><p class="font-medium">{{ __('communications::ui.channel.'.$i['channel']) }}: {{ $i['address'] }}</p><p class="text-xs text-slate-500">{{ __('communications::ui.channels.verified') }} {{ $i['at']->setTimezone(config('core.display_timezone'))->format('Y-m-d') }}</p></div>
                    <form method="POST" action="{{ route('comm.channels.unlink', $i['id']) }}">@csrf @method('DELETE')<x-core::button type="submit" variant="secondary" size="sm">{{ __('communications::ui.channels.remove') }}</x-core::button></form>
                </div>
            @empty
                <p class="text-sm text-slate-500">{{ __('communications::ui.channels.none') }}</p>
            @endforelse
        </div>

        @if ($pending)
            <form method="POST" action="{{ route('comm.channels.confirm') }}" class="space-y-3 rounded-xl border border-indigo-200 bg-indigo-50/40 p-4">
                @csrf <input type="hidden" name="channel" value="{{ $pending }}">
                <label for="code" class="block text-sm font-medium">{{ __('communications::ui.channels.enter_code') }}</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" class="{{ $field }}" required>
                @error('code') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                @error('address') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                <x-core::button type="submit">{{ __('communications::ui.channels.confirm') }}</x-core::button>
            </form>
        @endif

        <form method="POST" action="{{ route('comm.channels.start') }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-4" x-data="{ channel: @js(old('channel') === 'email' ? 'email' : 'whatsapp') }">
            @csrf
            <p class="text-sm font-medium">{{ __('communications::ui.channels.add') }}</p>
            <div class="grid grid-cols-3 gap-3">
                <select name="channel" x-model="channel" class="{{ $field }}" aria-label="{{ __('communications::ui.messages.channel') }}">@foreach (['whatsapp', 'email'] as $c) <option value="{{ $c }}" @selected(old('channel') === $c)>{{ __('communications::ui.channel.'.$c) }}</option> @endforeach</select>
                <input name="address" value="{{ old('address') }}" class="{{ $field }} col-span-2" placeholder="{{ __('communications::ui.channels.placeholder') }}" aria-label="{{ __('communications::ui.consents.address') }}" required>
            </div>
            @error('address') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            @error('form') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            @error('channel') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            @error('template') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            <p class="text-xs text-slate-600" x-show="channel === 'whatsapp'">{{ __('communications::ui.channels.consent.whatsapp') }}</p>
            <p class="text-xs text-slate-600" x-show="channel === 'email'" x-cloak>{{ __('communications::ui.channels.consent.email') }}</p>
            <x-core::button type="submit">{{ __('communications::ui.channels.send_code') }}</x-core::button>
        </form>
    </div>
</x-core::layouts.app>
