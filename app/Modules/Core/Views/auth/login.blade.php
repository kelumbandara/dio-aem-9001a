<x-core::layouts.guest :title="__('core::auth.sign_in')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('core::auth.sign_in') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('core::auth.sign_in_subtitle') }}</p>

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
        @csrf
        <x-core::input name="email" type="email" :label="__('core::auth.email')" :value="old('email')" autocomplete="username" required autofocus />
        <x-core::input name="password" type="password" :label="__('core::auth.password')" autocomplete="current-password" required />
        <x-core::input name="mfa_code" inputmode="numeric" :label="__('core::auth.mfa_code')" :hint="__('core::auth.mfa_code_hint')" autocomplete="one-time-code" />
        <x-core::button type="submit" class="w-full">{{ __('core::auth.sign_in') }}</x-core::button>
    </form>
</x-core::layouts.guest>
