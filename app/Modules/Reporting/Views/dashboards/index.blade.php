<x-core::layouts.app :title="__('reporting::ui.menu.dashboards')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('reporting::ui.intro') }}</p>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($dashboards as $b)
            <a href="{{ route('reporting.show', $b->key()) }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-indigo-400">
                <p class="text-base font-semibold text-slate-900">{{ $b->title() }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $b->summary() }}</p>
            </a>
        @empty
            <p class="text-sm text-slate-500">{{ __('reporting::ui.none_available') }}</p>
        @endforelse
    </div>
</x-core::layouts.app>
