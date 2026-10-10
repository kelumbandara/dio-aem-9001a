{{-- Responsive table: scrolls horizontally inside its card on narrow screens. Pass <thead>/<tbody> as the slot. --}}
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm']) }}>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            {{ $slot }}
        </table>
    </div>
</div>
