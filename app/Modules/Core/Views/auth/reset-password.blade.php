<x-core::layouts.guest :title="__('core::ui.password.reset_title')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('core::ui.password.reset_title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('core::ui.password.rules') }}</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-core::input name="email" type="email" :label="__('core::auth.email')" :value="old('email', $email)" autocomplete="username" required />
        <x-core::input name="password" type="password" :label="__('core::ui.password.new')" autocomplete="new-password" required autofocus />
        <x-core::input name="password_confirmation" type="password" :label="__('core::ui.password.confirm')" autocomplete="new-password" required />
        <x-core::button type="submit" class="w-full">{{ __('core::ui.password.save') }}</x-core::button>
    </form>
</x-core::layouts.guest>
