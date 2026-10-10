{{-- Alpine modal. Open with: $dispatch('open-modal', 'name') --}}
@props(['name', 'title'])
<div x-data="{ open: false }"
     x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="modal-{{ $name }}-title">
    <div class="fixed inset-0 bg-slate-900/50" x-show="open" x-transition.opacity @click="open = false"></div>
    <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" x-show="open" x-transition>
        <h2 id="modal-{{ $name }}-title" class="text-lg font-semibold text-slate-900">{{ $title }}</h2>
        <div class="mt-2 text-sm text-slate-600">{{ $slot }}</div>
        @isset($footer)
            <div class="mt-6 flex justify-end gap-3">{{ $footer }}</div>
        @endisset
    </div>
</div>
