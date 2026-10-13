@php
    $tone = ['sent' => 'info', 'delivered' => 'success', 'read' => 'success', 'failed' => 'danger', 'cancelled' => 'neutral', 'awaiting_approval' => 'warning', 'queued' => 'info', 'sending' => 'info', 'draft' => 'neutral', 'received' => 'info'];
@endphp
<x-core::layouts.app :title="__('communications::ui.messages.one')">
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif
    @error('form') <x-core::alert type="error">{{ $message }}</x-core::alert> @enderror
    @foreach (['address', 'template', 'channel'] as $f) @error($f) <x-core::alert type="error">{{ $message }}</x-core::alert> @enderror @endforeach

    <div class="max-w-2xl space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <x-core::status-badge :tone="$tone[$m->status] ?? 'neutral'">{{ __('communications::ui.status.'.$m->status) }}</x-core::status-badge>
            <span class="text-sm text-slate-600">{{ __('communications::ui.channel.'.$m->channel) }} {{ $m->direction === 'in' ? '←' : '→' }} {{ $to }}</span>
            @if ($m->drafted_by_ai) <span class="text-xs text-slate-500">{{ __('communications::ui.messages.drafted_by_ai') }}</span> @endif
        </div>
        @if ($m->subject) <p class="font-medium text-slate-900">{{ $m->subject }}</p> @endif
        <div class="whitespace-pre-line rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-800 shadow-sm">{{ $m->body }}</div>
        @if ($m->error) <p class="text-sm text-red-700">{{ $m->error }}</p> @endif
        @if ($m->status === 'awaiting_approval' && $m->approval_request_id) <p class="text-sm text-slate-600"><a class="text-indigo-600 hover:underline" href="{{ route('approvals.show', $m->approval_request_id) }}">{{ __('communications::ui.messages.see_approval') }}</a></p> @endif
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-slate-600">
            @foreach (['created_at', 'queued_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at'] as $f) @if ($m->$f)<dt>{{ __('communications::ui.messages.'.$f) }}</dt><dd>{{ $m->$f->setTimezone(config('core.display_timezone'))->format('Y-m-d H:i') }}</dd>@endif @endforeach
        </dl>
        <div class="flex gap-2">
            @if ($canSend && in_array($m->status, ['draft', 'failed'], true))
                <form method="POST" action="{{ route('comm.messages.send', $m->public_id) }}">@csrf <x-core::button type="submit">{{ __('communications::ui.messages.send') }}</x-core::button></form>
            @endif
            @if ($canDraft && in_array($m->status, ['draft', 'awaiting_approval', 'failed'], true))
                <form method="POST" action="{{ route('comm.messages.cancel', $m->public_id) }}">@csrf <x-core::button type="submit" variant="secondary">{{ __('communications::ui.messages.cancel') }}</x-core::button></form>
            @endif
        </div>
    </div>
</x-core::layouts.app>
