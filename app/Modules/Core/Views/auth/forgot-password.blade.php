<x-core::layouts.guest :title="__('core::ui.password.forgot_title')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('core::ui.password.forgot_title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('core::ui.password.forgot_hint') }}</p>
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <x-core::input name="email" type="email" :label="__('core::auth.email')" :value="old('email')" autocomplete="username" required autofocus />
        <x-core::button type="submit" class="w-full">{{ __('core::ui.password.send_link') }}</x-core::button>
        <p class="text-center text-sm"><a class="text-indigo-700 hover:underline" href="{{ route('login') }}">{{ __('core::ui.password.back_to_login') }}</a></p>
    </form>
</x-core::layouts.guest>
