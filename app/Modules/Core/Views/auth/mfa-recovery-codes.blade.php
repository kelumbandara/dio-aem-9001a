<x-core::layouts.guest :title="__('core::auth.recovery_title')">
    <h1 class="text-xl font-semibold text-slate-900">{{ __('core::auth.recovery_title') }}</h1>
    <p class="mt-1 text-sm text-slate-500">{{ __('core::auth.recovery_intro') }}</p>

    <ul class="mt-6 grid grid-cols-2 gap-2 font-mono text-sm text-slate-800">
        @foreach ($codes as $code)
            <li class="rounded bg-slate-100 px-2 py-1 text-center">{{ $code }}</li>
        @endforeach
    </ul>

    <a href="{{ route('dashboard') }}" class="mt-6 block">
        <x-core::button class="w-full">{{ __('core::auth.continue') }}</x-core::button>
    </a>
</x-core::layouts.guest>
