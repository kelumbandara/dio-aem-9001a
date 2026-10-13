<x-core::layouts.app :title="__('core::ui.password.change_title')">
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif
    <form method="POST" action="{{ route('account.password.update') }}" class="max-w-md space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @method('PUT')
        <p class="text-sm text-slate-600">{{ __('core::ui.password.rules') }} {{ __('core::ui.password.change_hint') }}</p>
        <x-core::input name="current_password" type="password" :label="__('core::ui.password.current')" autocomplete="current-password" required />
        <x-core::input name="password" type="password" :label="__('core::ui.password.new')" autocomplete="new-password" required />
        <x-core::input name="password_confirmation" type="password" :label="__('core::ui.password.confirm')" autocomplete="new-password" required />
        <x-core::button type="submit">{{ __('core::ui.password.save') }}</x-core::button>
    </form>
</x-core::layouts.app>
