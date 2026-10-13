@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $current = strtolower((string) old('primary', $values['primary']));
    $currentHex = isset($presets[$current]) ? $presets[$current] : $current;
    $presetId = array_search(strtolower($currentHex), array_map('strtolower', $presets), true);
    $custom = $presetId === false ? $currentHex : '';
    $selected = $presetId === false ? '' : $presetId;
    $state = [
        'primary' => $selected, 'custom_primary' => old('custom_primary', $custom), 'sidebar' => old('sidebar', $values['sidebar']),
        'font' => old('font', $values['font']), 'font_size' => old('font_size', $values['font_size']), 'radius' => old('radius', $values['radius']),
    ];
@endphp
<x-core::layouts.app :title="__('core::ui.appearance.title')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('core::ui.appearance.intro') }}</p>

    <div class="grid max-w-6xl gap-6 lg:grid-cols-5" x-data="appearanceForm(@js($state), @js(route('appearance.preview')))" x-init="refresh()">
        <form method="POST" action="{{ route('appearance.update') }}" enctype="multipart/form-data" class="space-y-6 lg:col-span-3">
            @csrf
            @method('PUT')

            <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('core::ui.appearance.identity') }}</h2>
                <x-core::input name="app_name" :label="__('core::ui.appearance.app_name')" :value="old('app_name', $values['app_name'])" maxlength="60" :hint="__('core::ui.appearance.app_name_hint')" />
                <div>
                    <label for="f-logo" class="mb-1 block text-sm font-medium text-slate-700">{{ __('core::ui.appearance.logo') }}</label>
                    <div class="flex flex-wrap items-center gap-4">
                        @if ($logoUrl) <img src="{{ $logoUrl }}" alt="" class="h-14 max-w-40 rounded-md border border-slate-200 bg-white object-contain p-1"> @endif
                        <input id="f-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="text-sm text-slate-600 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                        @if ($logoUrl) <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300 text-indigo-600"> {{ __('core::ui.appearance.remove_logo') }}</label> @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ __('core::ui.appearance.logo_hint') }}</p>
                    @error('logo') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-core::input name="login_message" :label="__('core::ui.appearance.login_message')" :value="old('login_message', $values['login_message'])" maxlength="200" />
                <x-core::input name="document_footer" :label="__('core::ui.appearance.document_footer')" :value="old('document_footer', $values['document_footer'])" maxlength="200" :hint="__('core::ui.appearance.document_footer_hint')" />
            </section>

            <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('core::ui.appearance.colours') }}</h2>
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-slate-700">{{ __('core::ui.appearance.primary') }}</legend>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($presets as $id => $hex)
                            <label class="flex cursor-pointer flex-col items-center gap-1 text-xs text-slate-600">
                                <input type="radio" name="primary" value="{{ $id }}" x-model="s.primary" @change="s.custom_primary = ''; refresh()" class="peer sr-only">
                                <span class="h-9 w-9 rounded-full ring-2 ring-offset-2 ring-transparent peer-checked:ring-slate-900 peer-focus-visible:ring-slate-500" style="background: {{ $hex }}"></span>
                                {{ __("core::ui.appearance.presets.{$id}") }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-40">
                        <label for="f-custom" class="mb-1 block text-sm font-medium text-slate-700">{{ __('core::ui.appearance.custom') }}</label>
                        <input id="f-custom" name="custom_primary" x-model="s.custom_primary" @input.debounce.400ms="s.primary = ''; refresh()" placeholder="#1d4ed8" maxlength="7" class="{{ $field }} font-mono">
                    </div>
                    <input type="color" aria-label="{{ __('core::ui.appearance.custom') }}" class="h-10 w-12 cursor-pointer rounded border border-slate-300 p-0.5" :value="s.custom_primary || '#4f46e5'" @input="s.custom_primary = $event.target.value; s.primary = ''; refresh()">
                    <p class="basis-full text-xs text-slate-500">{{ __('core::ui.appearance.custom_hint') }}</p>
                </div>
                @error('primary') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-slate-700">{{ __('core::ui.appearance.sidebar') }}</legend>
                    <div class="flex flex-wrap gap-4 text-sm">
                        @foreach ($sidebars as $option)
                            <label class="flex items-center gap-2"><input type="radio" name="sidebar" value="{{ $option }}" x-model="s.sidebar" @change="refresh()" class="border-slate-300 text-indigo-600"> {{ __("core::ui.appearance.sidebars.{$option}") }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="print_mono" value="0"><input type="checkbox" name="print_mono" value="1" class="rounded border-slate-300 text-indigo-600" @checked(old('print_mono', $values['print_mono']))> {{ __('core::ui.appearance.print_mono') }}</label>
            </section>

            <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('core::ui.appearance.text') }}</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="f-font" class="mb-1 block text-sm font-medium text-slate-700">{{ __('core::ui.appearance.font') }}</label>
                        <select id="f-font" name="font" x-model="s.font" @change="refresh()" class="{{ $field }}">
                            @foreach ($fonts as $option) <option value="{{ $option }}">{{ __("core::ui.appearance.fonts.{$option}") }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-size" class="mb-1 block text-sm font-medium text-slate-700">{{ __('core::ui.appearance.font_size') }}</label>
                        <select id="f-size" name="font_size" x-model="s.font_size" @change="refresh()" class="{{ $field }}">
                            @foreach ($sizes as $option) <option value="{{ $option }}">{{ __("core::ui.appearance.sizes.{$option}") }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-radius" class="mb-1 block text-sm font-medium text-slate-700">{{ __('core::ui.appearance.radius') }}</label>
                        <select id="f-radius" name="radius" x-model="s.radius" @change="refresh()" class="{{ $field }}">
                            @foreach ($radii as $option) <option value="{{ $option }}">{{ __("core::ui.appearance.radii.{$option}") }}</option> @endforeach
                        </select>
                    </div>
                </div>
                <p class="text-xs text-slate-500">{{ __('core::ui.appearance.text_hint') }}</p>
            </section>

            @if ($vendor)
                <section class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('core::ui.appearance.vendor') }}</h2>
                    <p class="text-xs text-slate-600">{{ __('core::ui.appearance.vendor_hint') }}</p>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="powered_by_enabled" value="1" class="rounded border-slate-300 text-indigo-600" @checked(old('powered_by_enabled', $values['powered_by_enabled']))> {{ __('core::ui.appearance.powered_by_enabled') }}</label>
                    <x-core::input name="powered_by_text" :label="__('core::ui.appearance.powered_by_text')" :value="old('powered_by_text', $values['powered_by_text'])" maxlength="60" />
                </section>
            @endif

            <div class="flex gap-3">
                <x-core::button type="submit">{{ __('core::ui.common.save') }}</x-core::button>
            </div>
        </form>

        <div class="space-y-4 lg:col-span-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-base font-semibold text-slate-900">{{ __('core::ui.appearance.preview') }}</h2>
                <style x-text="scoped"></style>
                <div id="appearance-preview" class="overflow-hidden rounded-xl border border-slate-200 text-sm" :style="`zoom: ${zoom}; font-family: var(--font-sans)`">
                    <div class="flex">
                        <div class="w-36 shrink-0 space-y-1 border-e border-(--sidebar-border) bg-(--sidebar-bg) p-3 text-(--sidebar-text)">
                            <div class="mb-3 truncate font-semibold text-(--sidebar-title)">{{ $values['app_name'] ?: __('core::ui.app_name') }}</div>
                            <div class="rounded-lg bg-(--sidebar-active-bg) px-2 py-1.5 text-(--sidebar-active-text)">{{ __('core::ui.menu.dashboard') }}</div>
                            <div class="rounded-lg px-2 py-1.5 hover:bg-(--sidebar-hover)">{{ __('core::ui.appearance.sample_menu') }}</div>
                        </div>
                        <div class="min-w-0 flex-1 space-y-3 bg-slate-50 p-4 text-slate-800">
                            <div class="font-semibold text-slate-900">{{ __('core::ui.appearance.sample_title') }}</div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm">{{ __('core::ui.common.save') }}</span>
                                <span class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm">{{ __('core::ui.common.cancel') }}</span>
                                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">{{ __('core::ui.appearance.sample_badge') }}</span>
                            </div>
                            <div class="rounded-lg bg-white px-3 py-2 shadow-sm ring-1 ring-inset ring-slate-300 focus-within:ring-2">INV/2026-27/00001 · <span class="text-indigo-600">LKR 12,980.00</span></div>
                            <p class="text-slate-600">{{ __('core::ui.appearance.sample_text') }}</p>
                            <p class="text-slate-600">සිංහල අකුරු පෙන්වන ආකාරය · தமிழ் எழுத்துக்கள் காட்டப்படும் விதம்</p>
                        </div>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ __('core::ui.appearance.preview_hint') }}</p>
            </section>

            <form method="POST" action="{{ route('appearance.reset') }}" x-on:submit="if (! confirm(@js(__('core::ui.appearance.reset_confirm')))) $event.preventDefault()">
                @csrf
                <x-core::button type="submit" variant="secondary">{{ __('core::ui.appearance.reset') }}</x-core::button>
            </form>
        </div>
    </div>

    <script nonce="{{ Vite::cspNonce() }}">
        function appearanceForm(state, previewUrl) {
            return {
                s: state, scoped: '', zoom: 1,
                async refresh() {
                    const body = { primary: this.s.primary, custom_primary: this.s.custom_primary, sidebar: this.s.sidebar, font: this.s.font, font_size: this.s.font_size, radius: this.s.radius };
                    try {
                        const r = await fetch(previewUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(body) });
                        const css = (await r.json()).css || '';
                        // The same CSS the real screens get, applied to the preview box only.
                        this.scoped = css.replace(/^:root\s*\{/, '#appearance-preview {').replace(/\nhtml\s*\{[^}]*\}\s*$/, '');
                        this.zoom = { small: 14, normal: 16, large: 18 }[this.s.font_size] / 16;
                    } catch (e) { /* the preview is a convenience; saving does not depend on it */ }
                },
            };
        }
    </script>
</x-core::layouts.app>
