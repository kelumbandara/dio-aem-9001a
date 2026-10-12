<x-core::layouts.app :title="__('manufacturing::ui.setup.title')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('manufacturing::ui.setup.intro') }}</p>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($entities as $e)
            <a href="{{ route('manufacturing.setup.list', $e['key']) }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-indigo-300">
                <div class="text-base font-semibold text-slate-900">{{ __("manufacturing::ui.setup.{$e['key']}.many") }}</div>
                <div class="mt-1 text-sm text-slate-600">{{ __("manufacturing::ui.setup.{$e['key']}.hint") }}</div>
                <div class="mt-3 text-2xl font-semibold text-indigo-700">{{ $e['count'] }}</div>
            </a>
        @endforeach
        <a href="{{ route('manufacturing.targets.index') }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-indigo-300">
            <div class="text-base font-semibold text-slate-900">{{ __('manufacturing::ui.targets.title') }}</div>
            <div class="mt-1 text-sm text-slate-600">{{ __('manufacturing::ui.targets.hint') }}</div>
            <div class="mt-3 text-2xl font-semibold text-indigo-700">{{ \App\Modules\Manufacturing\Models\ShiftTarget::query()->where('is_active', true)->count() }}</div>
        </a>
    </div>
</x-core::layouts.app>
