<x-core::layouts.app :title="$user ? $user->name : __('core::ui.users.add')">
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif
    @error('form') <p class="mb-3 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    @php $canManage = auth()->user()->can('core.user.manage'); @endphp

    <form method="POST" action="{{ $user ? route('users.update', $user) : route('users.store') }}" class="max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @if ($user) @method('PUT') @endif
        @unless ($user) <p class="text-sm text-slate-600">{{ __('core::ui.users.new_hint') }}</p> @endunless
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-core::input name="name" :label="__('core::ui.users.name')" :value="old('name', $user?->name)" required />
            <x-core::input name="email" type="email" :label="__('core::ui.users.email')" :value="old('email', $user?->email)" required />
            <div>
                <label for="f-locale" class="mb-1 block text-sm font-medium text-slate-700">{{ __('core::ui.users.language') }}</label>
                <select id="f-locale" name="locale" class="block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300">
                    @foreach (config('core.locales') as $l) <option value="{{ $l }}" @selected(old('locale', $user?->locale ?? config('core.default_locale')) === $l)>{{ strtoupper($l) }}</option> @endforeach
                </select>
            </div>
            @if ($user)
                <label class="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) class="rounded border-slate-300"> {{ __('core::ui.users.active') }}</label>
            @endif
        </div>

        <fieldset>
            <legend class="mb-1 text-sm font-medium text-slate-700">{{ __('core::ui.users.roles') }}</legend>
            @error('roles') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach ($roles as $r)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="roles[]" value="{{ $r }}" @checked(in_array($r, old('roles', $mine), true)) class="rounded border-slate-300"> {{ $r }}</label>
                @endforeach
            </div>
        </fieldset>
        @if ($canManage) <div class="pt-2"><x-core::button type="submit">{{ __('core::ui.common.save') }}</x-core::button></div> @endif
    </form>

    @if ($user && $canManage)
        <div class="mt-6 max-w-2xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-600">{{ __('core::ui.users.security_hint') }}</p>
            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('users.password-link', $user) }}">@csrf<x-core::button type="submit" variant="secondary">{{ __('core::ui.users.send_link') }}</x-core::button></form>
                <form method="POST" action="{{ route('users.reset-mfa', $user) }}" x-on:submit="if (! confirm(@js(__('core::ui.users.mfa_confirm')))) $event.preventDefault()">@csrf<x-core::button type="submit" variant="secondary">{{ __('core::ui.users.reset_mfa') }}</x-core::button></form>
            </div>
            <p class="text-xs text-slate-500">{{ $user->hasMfaEnabled() ? __('core::ui.users.mfa_on') : __('core::ui.users.mfa_off') }}</p>
        </div>
    @endif
</x-core::layouts.app>
