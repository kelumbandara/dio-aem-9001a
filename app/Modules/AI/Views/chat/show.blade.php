<x-core::layouts.app :title="__('ai::ui.menu.assistant')">
    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <aside class="space-y-2">
            <a href="{{ route('ai.chat.index') }}" class="block rounded-lg bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-indigo-700">{{ __('ai::ui.new_chat') }}</a>
            @foreach ($conversations as $c)
                <a href="{{ route('ai.chat.show', $c->public_id) }}" class="block truncate rounded-lg px-3 py-2 text-sm {{ $conversation && $conversation->id === $c->id ? 'bg-white font-medium ring-1 ring-indigo-300' : 'text-slate-700 hover:bg-white' }}">{{ $c->title }}</a>
            @endforeach
        </aside>

        <section class="min-w-0">
            @unless ($configured)
                <x-core::alert type="error">{{ __('ai::ui.not_configured') }}</x-core::alert>
            @endunless
            <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('ai::ui.intro') }}</p>

            <div class="space-y-4" data-chat>
                @forelse ($messages as $m)
                    <div class="flex {{ $m->role === 'user' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm shadow-sm {{ $m->role === 'user' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-800 ring-1 ring-slate-200' }}">
                            <p class="whitespace-pre-line break-words">{{ $m->content }}</p>
                            @if ($m->role === 'assistant' && ! empty($m->refs))
                                <p class="mt-2 border-t border-slate-100 pt-2 text-xs text-slate-500">
                                    {{ __('ai::ui.based_on') }}
                                    @foreach ($m->refs as $r)
                                        <a class="text-indigo-600 hover:underline" href="{{ $r['url'] }}">{{ $r['label'] }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
                        <p class="mb-2 font-medium text-slate-700">{{ __('ai::ui.try_asking') }}</p>
                        <ul class="list-inside list-disc space-y-1">
                            <li>{{ __('ai::ui.example_1') }}</li>
                            <li>{{ __('ai::ui.example_2') }}</li>
                            <li>{{ __('ai::ui.example_3') }}</li>
                        </ul>
                    </div>
                @endforelse
            </div>

            <form method="POST" action="{{ $conversation ? route('ai.chat.message', $conversation->public_id) : route('ai.chat.start') }}" class="mt-6">
                @csrf
                <label for="message" class="sr-only">{{ __('ai::ui.your_question') }}</label>
                <textarea id="message" name="message" rows="3" maxlength="{{ config('ai.max_message_chars') }}" required placeholder="{{ __('ai::ui.placeholder') }}"
                          class="block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">{{ old('message') }}</textarea>
                @error('message') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                <div class="mt-2 flex items-center justify-between">
                    <p class="text-xs text-slate-500">{{ __('ai::ui.read_only') }}</p>
                    <x-core::button type="submit">{{ __('ai::ui.ask') }}</x-core::button>
                </div>
            </form>
        </section>
    </div>
</x-core::layouts.app>
