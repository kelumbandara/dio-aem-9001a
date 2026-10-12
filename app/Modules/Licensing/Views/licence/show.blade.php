@php
    $tones = ['active' => 'success', 'grace' => 'warning', 'readonly' => 'danger', 'locked' => 'danger'];
    $field = 'block w-full rounded-lg border-0 px-3 py-2 font-mono text-xs shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('licensing::ui.title')">
    @if (! $enforcing)
        <x-core::alert type="info" class="mb-4">{{ __('licensing::ui.not_enforced') }}</x-core::alert>
    @endif

    <div class="grid max-w-5xl gap-6 lg:grid-cols-3">
        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
            <div class="flex flex-wrap items-center gap-3">
                <x-core::status-badge :tone="$tones[$status->state]">{{ __('licensing::ui.state.'.$status->state) }}</x-core::status-badge>
                <span class="text-sm text-slate-600">{{ __('licensing::ui.source.'.$status->source) }}</span>
            </div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">{{ __('licensing::ui.valid_until') }}</dt><dd class="font-medium text-slate-900">{{ $status->validUntil }}</dd></div>
                <div>
                    <dt class="text-slate-500">{{ $status->state === 'active' ? __('licensing::ui.days_left') : __('licensing::ui.days_over') }}</dt>
                    <dd class="font-medium text-slate-900">{{ $status->state === 'active' ? $status->daysLeft : $status->daysOver }}</dd>
                </div>
                <div><dt class="text-slate-500">{{ __('licensing::ui.company') }}</dt><dd class="font-medium text-slate-900">{{ $status->company ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('licensing::ui.plan') }}</dt><dd class="font-medium text-slate-900">{{ $status->plan ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('licensing::ui.user_limit') }}</dt><dd class="font-medium text-slate-900">{{ $status->maxUsers ?? __('licensing::ui.no_limit') }}</dd></div>
                <div><dt class="text-slate-500">{{ __('licensing::ui.modules') }}</dt><dd class="font-medium text-slate-900">{{ $status->modules ? implode(', ', $status->modules) : __('licensing::ui.all_modules') }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">{{ __('licensing::ui.serial') }}</dt><dd class="font-mono text-xs text-slate-700">{{ $status->serial ?? '—' }}</dd></div>
            </dl>
            <div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                <div class="font-medium text-slate-800">{{ __('licensing::ui.phases_title') }}</div>
                <ul class="mt-1 list-disc space-y-0.5 ps-5">
                    <li>{{ __('licensing::ui.phase_active') }}</li>
                    <li>{{ __('licensing::ui.phase_grace', ['days' => $grace, 'from' => $status->validUntil]) }}</li>
                    <li>{{ __('licensing::ui.phase_readonly', ['days' => $readonly]) }}</li>
                    <li>{{ __('licensing::ui.phase_locked') }}</li>
                </ul>
            </div>
            @if ($renewNote) <p class="text-sm text-slate-700">{{ $renewNote }}</p> @else <p class="text-sm text-slate-600">{{ __('licensing::ui.renew') }}</p> @endif
        </section>

        <section class="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('licensing::ui.installation_id') }}</h2>
            <p class="break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900" x-data x-on:click="navigator.clipboard && navigator.clipboard.writeText($el.innerText.trim())" title="{{ __('licensing::ui.copy') }}">{{ $installationId }}</p>
            <p class="text-xs text-slate-500">{{ __('licensing::ui.installation_hint') }}</p>
        </section>
    </div>

    @if ($canManage)
        <form method="POST" action="{{ route('licence.store') }}" class="mt-6 max-w-5xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <h2 class="text-base font-semibold text-slate-900">{{ __('licensing::ui.paste_title') }}</h2>
            <p class="text-xs text-slate-500">{{ __('licensing::ui.paste_hint') }}</p>
            <textarea name="licence" rows="4" required class="{{ $field }}" placeholder="DIO1.…">{{ old('licence') }}</textarea>
            @error('licence') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <x-core::button type="submit">{{ __('licensing::ui.install') }}</x-core::button>
        </form>
    @endif

    @if ($history->isNotEmpty())
        <h2 class="mb-2 mt-8 text-base font-semibold text-slate-900">{{ __('licensing::ui.history') }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('licensing::ui.serial') }}</th><th class="px-4 py-3 text-start font-medium">{{ __('licensing::ui.plan') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('licensing::ui.valid_from') }}</th><th class="px-4 py-3 text-start font-medium">{{ __('licensing::ui.valid_until') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('licensing::ui.issued') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($history as $l)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $l->serial }}</td><td class="px-4 py-3">{{ $l->plan }}</td>
                        <td class="px-4 py-3">{{ $l->valid_from->toDateString() }}</td><td class="px-4 py-3">{{ $l->valid_until->toDateString() }}</td>
                        <td class="px-4 py-3"><x-core::datetime :value="$l->issued_at" /></td>
                    </tr>
                @endforeach
            </tbody>
        </x-core::table>
    @endif
</x-core::layouts.app>
