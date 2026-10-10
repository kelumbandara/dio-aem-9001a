<x-core::layouts.guest :title="__('core::auth.mfa_setup_title')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('core::auth.mfa_setup_title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('core::auth.mfa_setup_intro') }}</p>

    <div class="mt-6 flex justify-center rounded-lg border border-slate-200 bg-white p-3">{!! $setup['qr_svg'] !!}</div>
    <p class="mt-4 text-xs text-slate-500">{{ __('core::auth.mfa_manual') }}</p>
    <p class="mt-1 break-all rounded bg-slate-100 px-2 py-1 font-mono text-sm text-slate-800">{{ $setup['secret'] }}</p>

    <form method="POST" action="{{ route('mfa.confirm') }}" class="mt-6 space-y-4">
        @csrf
        <x-core::input name="code" inputmode="numeric" :label="__('core::auth.mfa_code')" autocomplete="one-time-code" required />
        <x-core::button type="submit" class="w-full">{{ __('core::auth.mfa_confirm') }}</x-core::button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm text-slate-500 hover:text-slate-700">{{ __('core::ui.common.sign_out') }}</button>
    </form>
</x-core::layouts.guest>
