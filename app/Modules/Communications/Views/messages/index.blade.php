@php
    $field = 'block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $tone = ['sent' => 'info', 'delivered' => 'success', 'read' => 'success', 'failed' => 'danger', 'cancelled' => 'neutral', 'awaiting_approval' => 'warning', 'queued' => 'info', 'sending' => 'info', 'draft' => 'neutral', 'received' => 'info'];
@endphp
<x-core::layouts.app :title="__('communications::ui.menu.messages')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('communications::ui.messages.intro') }}</p>
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <div><label for="status" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.messages.status') }}</label>
            <select id="status" name="status" class="{{ $field }}"><option value="">{{ __('communications::ui.all') }}</option>@foreach ($statuses as $s) <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('communications::ui.status.'.$s) }}</option> @endforeach</select></div>
        <div><label for="channel" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.messages.channel') }}</label>
            <select id="channel" name="channel" class="{{ $field }}"><option value="">{{ __('communications::ui.all') }}</option>@foreach (['email', 'whatsapp'] as $c) <option value="{{ $c }}" @selected(($filters['channel'] ?? '') === $c)>{{ __('communications::ui.channel.'.$c) }}</option> @endforeach</select></div>
        <x-core::button type="submit" variant="secondary">{{ __('communications::ui.filter') }}</x-core::button>
        @if ($canDraft) <a href="{{ route('comm.messages.create') }}" class="ms-auto rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ __('communications::ui.messages.new') }}</a> @endif
    </form>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.when') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.channel') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.to') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.what') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($page as $m)
                <tr>
                    <td class="px-4 py-3 text-slate-600">{{ $m->created_at->setTimezone(config('core.display_timezone'))->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3">{{ __('communications::ui.channel.'.$m->channel) }} {{ $m->direction === 'in' ? '←' : '→' }}</td>
                    <td class="px-4 py-3"><a class="text-indigo-600 hover:underline" href="{{ route('comm.messages.show', $m->public_id) }}">{{ \App\Modules\Communications\Services\Address::mask($m->channel, $m->address) }}</a></td>
                    <td class="px-4 py-3">{{ $m->template_key ?? '—' }} @if ($m->drafted_by_ai) <span class="text-xs text-slate-500">({{ __('communications::ui.messages.by_ai') }})</span> @endif</td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$tone[$m->status] ?? 'neutral'">{{ __('communications::ui.status.'.$m->status) }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td class="px-4 py-6 text-center text-slate-500" colspan="5">{{ __('communications::ui.messages.none') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $page->links() }}</div>
</x-core::layouts.app>
