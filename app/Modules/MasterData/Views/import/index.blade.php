<x-core::layouts.app :title="__('masterdata::ui.import.title')">
    <p class="mb-6 max-w-3xl text-sm text-slate-600">{{ __('masterdata::ui.import.intro') }}</p>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <form method="POST" action="{{ route('masterdata.import.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label for="f-kind" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.import.kind') }}</label>
                    <select id="f-kind" name="kind" required class="block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">
                        @foreach ($kinds as $k) <option value="{{ $k }}" @selected(old('kind', $result['kind'] ?? null) === $k)>{{ __("masterdata::ui.import.kinds.{$k}") }}</option> @endforeach
                    </select>
                    @error('kind') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-file" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.import.file') }}</label>
                    <input id="f-file" name="file" type="file" accept=".csv,text/csv,text/plain" required class="block w-full text-sm text-slate-700 file:me-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium">
                    <p class="mt-1 text-xs text-slate-500">{{ __('masterdata::ui.import.file_hint') }}</p>
                    @error('file') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <fieldset class="space-y-2 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="mode" value="check" checked> {{ __('masterdata::ui.import.mode_check') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" name="mode" value="import"> {{ __('masterdata::ui.import.mode_import') }}</label>
                </fieldset>
                <x-core::button type="submit">{{ __('masterdata::ui.import.run') }}</x-core::button>
            </form>

            <h2 class="mt-6 text-sm font-semibold text-slate-900">{{ __('masterdata::ui.import.templates') }}</h2>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($kinds as $k) <li><a href="{{ route('masterdata.import.template', $k) }}" class="text-indigo-600 hover:underline">{{ __("masterdata::ui.import.kinds.{$k}") }}</a></li> @endforeach
            </ul>
        </section>

        <div class="space-y-6 xl:col-span-2">
            @if ($result)
                <section class="rounded-xl border bg-white p-5 shadow-sm {{ $result['errors'] ? 'border-red-300' : 'border-emerald-300' }}">
                    <h2 class="text-base font-semibold text-slate-900">
                        @if ($result['committed']) {{ __('masterdata::ui.import.done') }}
                        @elseif ($result['errors']) {{ __('masterdata::ui.import.problems') }}
                        @else {{ __('masterdata::ui.import.check_ok') }} @endif
                    </h2>
                    <p class="mt-1 text-sm text-slate-700">{{ __('masterdata::ui.import.summary', ['rows' => $result['rows'], 'created' => $result['created'], 'updated' => $result['updated']]) }}</p>
                    @if ($result['errors'])
                        <p class="mt-1 text-sm text-red-700">{{ __('masterdata::ui.import.nothing_saved') }}</p>
                        <x-core::table class="mt-3">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.import.line') }}</th>
                                    <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.import.column') }}</th>
                                    <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.import.problem') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach (array_slice($result['errors'], 0, 200) as $e)
                                    <tr><td class="px-4 py-2">{{ $e['row'] }}</td><td class="px-4 py-2 font-mono text-xs">{{ $e['field'] ?? '—' }}</td><td class="px-4 py-2">{{ $e['message'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </x-core::table>
                        @if (count($result['errors']) > 200) <p class="mt-2 text-xs text-slate-500">{{ __('masterdata::ui.import.first_200', ['total' => count($result['errors'])]) }}</p> @endif
                    @elseif (! $result['committed'])
                        <p class="mt-1 text-sm text-slate-600">{{ __('masterdata::ui.import.check_hint') }}</p>
                    @endif
                </section>
            @endif

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.import.columns') }}</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    @foreach ($kinds as $k)
                        <div><dt class="font-medium text-slate-800">{{ __("masterdata::ui.import.kinds.{$k}") }}</dt><dd class="mt-0.5 font-mono text-xs text-slate-600">{{ implode(', ', $columns[$k]) }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-3 text-xs text-slate-500">{{ __('masterdata::ui.import.rules') }}</p>
            </section>
        </div>
    </div>
</x-core::layouts.app>
