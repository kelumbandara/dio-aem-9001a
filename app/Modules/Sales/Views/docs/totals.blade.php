@php
    $num = fn ($v) => $v === null ? '' : (str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v);
    $taxes = $taxes ?? [];
@endphp
<dl class="ms-auto mt-4 w-full max-w-sm space-y-1 text-sm">
    <div class="flex justify-between"><dt class="text-slate-600">{{ __('sales::ui.common.subtotal') }}</dt><dd><x-core::money :value="$totals['net']" /></dd></div>
    @if ($taxes)
        @foreach ($taxes as $t)
            <div class="flex justify-between"><dt class="text-slate-600">{{ $t['name'] ?: $t['code'] }} ({{ $num($t['rate']) }}%)@if ($t['kind'] !== 'vat') <span class="text-xs text-slate-400">{{ __('sales::ui.common.on_base') }} <x-core::money :value="$t['base_amount']" /></span>@endif</dt><dd><x-core::money :value="$t['amount']" /></dd></div>
        @endforeach
    @else
        <div class="flex justify-between"><dt class="text-slate-600">{{ __('sales::ui.common.vat') }}</dt><dd><x-core::money :value="$totals['vat']" /></dd></div>
        <div class="flex justify-between"><dt class="text-slate-600">{{ __('sales::ui.common.levies') }}</dt><dd><x-core::money :value="$totals['levy']" /></dd></div>
    @endif
    <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900"><dt>{{ __('sales::ui.common.total') }}</dt><dd><x-core::money :value="$totals['gross']" /></dd></div>
</dl>
