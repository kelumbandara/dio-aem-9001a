<x-core::layouts.app :title="__('core::ui.notifications.title')">
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-slate-600">{{ __('core::ui.notifications.subtitle') }}</p>
        @if ($notifications->contains(fn ($n) => ! $n['read']))
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-core::button type="submit" variant="secondary" size="sm">{{ __('core::ui.notifications.mark_all_read') }}</x-core::button>
            </form>
        @endif
    </div>

    <ul class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        @forelse ($notifications as $n)
            @php $tone = ['success' => 'success', 'warning' => 'warning', 'danger' => 'danger'][$n['level']] ?? 'info'; @endphp
            <li @class(['px-4 py-3', 'bg-indigo-50/40' => ! $n['read']])>
                <form method="POST" action="{{ route('notifications.read', $n['id']) }}">
                    @csrf
                    <button type="submit" class="flex w-full items-start gap-3 text-start">
                        <x-core::status-badge :tone="$tone" class="mt-0.5 shrink-0">{{ __('core::ui.notifications.level.'.$n['level']) }}</x-core::status-badge>
                        <span class="min-w-0 flex-1">
                            <span @class(['block text-sm text-slate-900', 'font-semibold' => ! $n['read']])>{{ $n['title'] }}</span>
                            <span class="block text-sm text-slate-600">{{ $n['body'] }}</span>
                            <span class="mt-1 block text-xs text-slate-400"><x-core::datetime :value="\Carbon\CarbonImmutable::parse($n['created_at'])" /></span>
                        </span>
                    </button>
                </form>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-slate-500">{{ __('core::ui.notifications.empty') }}</li>
        @endforelse
    </ul>

    <div class="mt-4">{{ $notifications->links() }}</div>
</x-core::layouts.app>
