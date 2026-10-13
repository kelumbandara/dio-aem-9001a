@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('communications::ui.template.'.$t->key)">
    <form method="POST" action="{{ route('comm.templates.update', $t->public_id) }}" class="max-w-2xl space-y-4">
        @csrf @method('PUT')
        <p class="text-sm text-slate-600">{{ __('communications::ui.channel.'.$t->channel) }} · {{ $t->locale }} · {{ __('communications::ui.templates.values') }}: @foreach ($placeholders as $p) <code class="rounded bg-slate-100 px-1">{{ '{'.$p.'}' }}</code> @endforeach</p>
        @error('form') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
        @if ($t->channel === 'email')
            <div><label for="subject" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.templates.subject') }}</label>
                <input id="subject" name="subject" value="{{ old('subject', $t->subject) }}" class="{{ $field }}" maxlength="200">@error('subject') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror</div>
        @endif
        <div><label for="body" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.templates.body') }}</label>
            <textarea id="body" name="body" rows="7" class="{{ $field }}" maxlength="4000" required>{{ old('body', $t->body) }}</textarea>@error('body') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror</div>
        @if ($t->channel === 'whatsapp')
            <div class="grid grid-cols-2 gap-3">
                <div><label for="provider_template" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.templates.provider_template') }}</label>
                    <input id="provider_template" name="provider_template" value="{{ old('provider_template', $t->provider_template) }}" class="{{ $field }}" maxlength="100">@error('provider_template') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror</div>
                <div><label for="provider_language" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.templates.provider_language') }}</label>
                    <input id="provider_language" name="provider_language" value="{{ old('provider_language', $t->provider_language) }}" class="{{ $field }}" maxlength="10"></div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="approved" value="1" @checked(old('approved', $t->approved))> {{ __('communications::ui.templates.approved_by_whatsapp') }}</label>
        @endif
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="sensitive" value="1" @checked(old('sensitive', $t->sensitive))> {{ __('communications::ui.templates.sensitive') }}</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $t->is_active))> {{ __('communications::ui.templates.active') }}</label>
        <x-core::button type="submit">{{ __('communications::ui.save') }}</x-core::button>
    </form>
</x-core::layouts.app>
