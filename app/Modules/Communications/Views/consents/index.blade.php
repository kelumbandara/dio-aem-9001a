@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('communications::ui.menu.consents')">
    @if (session('status')) <x-core::alert type="success">{{ session('status') }}</x-core::alert> @endif
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('communications::ui.consents.intro') }}</p>
    <form method="GET" class="mb-6 flex flex-wrap items-end gap-2">
        <div><label for="channel" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.messages.channel') }}</label>
            <select id="channel" name="channel" class="{{ $field }}">@foreach (['email', 'whatsapp'] as $c) <option value="{{ $c }}" @selected($channel === $c)>{{ __('communications::ui.channel.'.$c) }}</option> @endforeach</select></div>
        <div><label for="address" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.consents.address') }}</label>
            <input id="address" name="address" value="{{ $raw }}" class="{{ $field }}" required></div>
        <x-core::button type="submit" variant="secondary">{{ __('communications::ui.consents.look_up') }}</x-core::button>
    </form>
    @if ($invalid) <x-core::alert type="error">{{ __('communications::ui.consents.invalid') }}</x-core::alert> @endif
    @if ($address)
        <div class="max-w-2xl space-y-4">
            <p class="text-lg font-semibold">{{ \App\Modules\Communications\Services\Address::display($channel, $address) }}: {{ __('communications::ui.consents.status.'.$status) }}</p>
            @if ($canManage)
                <form method="POST" action="{{ route('comm.consents.store') }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                    @csrf <input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="address" value="{{ $raw }}">
                    <div><label for="status_new" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.consents.record') }}</label>
                        <select id="status_new" name="status" class="{{ $field }}"><option value="opted_in">{{ __('communications::ui.consents.status.opted_in') }}</option><option value="opted_out">{{ __('communications::ui.consents.status.opted_out') }}</option></select></div>
                    <div><label for="note" class="mb-1 block text-xs font-medium text-slate-600">{{ __('communications::ui.consents.note') }}</label>
                        <input id="note" name="note" class="{{ $field }}" maxlength="255" required>@error('note') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror</div>
                    <x-core::button type="submit">{{ __('communications::ui.save') }}</x-core::button>
                </form>
            @endif
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.when') }}</th><th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.messages.status') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.consents.source') }}</th><th class="px-4 py-3 text-start font-medium">{{ __('communications::ui.consents.note') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($history as $h)
                        <tr><td class="px-4 py-2">{{ $h->created_at->setTimezone(config('core.display_timezone'))->format('Y-m-d H:i') }}</td><td class="px-4 py-2">{{ __('communications::ui.consents.status.'.$h->status) }}</td><td class="px-4 py-2">{{ __('communications::ui.consents.sources.'.$h->source) }}</td><td class="px-4 py-2">{{ $h->note }}</td></tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-slate-500" colspan="4">{{ __('communications::ui.consents.nothing') }}</td></tr>
                    @endforelse
                </tbody>
            </x-core::table>
        </div>
    @endif
</x-core::layouts.app>
