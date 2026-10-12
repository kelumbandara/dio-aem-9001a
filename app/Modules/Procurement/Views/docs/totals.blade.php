<dl class="ms-auto mt-4 w-full max-w-sm space-y-1 text-sm">
    <div class="flex justify-between"><dt class="text-slate-600">{{ __('procurement::ui.common.subtotal') }}</dt><dd><x-core::money :value="$totals['net']" /></dd></div>
    <div class="flex justify-between"><dt class="text-slate-600">{{ __('procurement::ui.common.vat') }}</dt><dd><x-core::money :value="$totals['vat']" /></dd></div>
    <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900"><dt>{{ __('procurement::ui.common.total') }}</dt><dd><x-core::money :value="$totals['gross']" /></dd></div>
</dl>
