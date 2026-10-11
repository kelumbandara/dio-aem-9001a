@php
    $tones = ['draft' => 'neutral', 'confirmed' => 'info', 'completed' => 'success', 'cancelled' => 'danger'];
    $st = $doc['status'];
    $num = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
    $field = 'block w-full rounded-lg border-0 px-2 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $today = now(config('core.display_timezone'))->toDateString();
    $deliverable = collect($doc['lines'])->filter(fn ($l) => $l['stocked'] && bccomp($l['qty'], $l['qty_delivered'], 6) > 0)->values();
    $billable = collect($doc['lines'])->map(fn ($l) => $l + ['billable' => bcsub($l['stocked'] ? $l['qty_delivered'] : $l['qty'], $l['qty_invoiced'], 6)])->filter(fn ($l) => bccomp($l['billable'], '0', 6) > 0)->values();
@endphp
<x-core::layouts.app :title="$doc['document_no']">
    @foreach (['form', 'credit', 'version', 'lines'] as $e) @error($e) <x-core::alert type="error" class="mb-4">{{ $message }}</x-core::alert> @enderror @endforeach

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="text-sm text-slate-600">
            <x-core::status-badge :tone="$tones[$st] ?? 'neutral'">{{ __('sales::ui.status.'.$st) }}</x-core::status-badge>
            <span class="ms-2">{{ $doc['customer_name'] }} <span class="font-mono text-xs">{{ $doc['customer'] }}</span></span>
            <div class="mt-1">{{ __('sales::ui.common.date') }}: {{ $doc['order_date'] }}@if ($doc['required_date']) · {{ __('sales::ui.form.required_date') }}: {{ $doc['required_date'] }}@endif · {{ __('sales::ui.common.warehouse') }}: {{ $doc['warehouse'] ?? '—' }} · {{ __('sales::ui.form.terms_days') }}: {{ $doc['payment_terms_days'] }}</div>
            @if ($doc['quotation']) <div>{{ __('sales::ui.titles.quotations') }}: {{ $doc['quotation'] }}</div> @endif
            @if ($doc['customer_ref']) <div>{{ __('sales::ui.form.customer_ref') }}: {{ $doc['customer_ref'] }}</div> @endif
            @if ($doc['credit_override_reason']) <div class="text-amber-700">{{ __('sales::ui.credit.overridden', ['reason' => $doc['credit_override_reason']]) }}</div> @endif
            @if ($doc['cancel_reason']) <div class="text-red-700">{{ $doc['cancel_reason'] }}</div> @endif
        </div>
        @if ($st === 'draft')
            @can('sales.order.manage')
                <a href="{{ route('sales.orders.edit', $doc['id']) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('sales::ui.common.edit') }}</a>
            @endcan
        @endif
    </div>

    @if ($st === 'draft')
        @can('sales.order.confirm')
            <form method="POST" action="{{ route('sales.orders.confirm', $doc['id']) }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                @csrf
                <p class="basis-full text-sm text-slate-700">{{ __('sales::ui.credit.confirm_help') }}</p>
                @can('sales.credit.override')
                    <div class="min-w-64 flex-1">
                        <label for="override_reason" class="mb-1 block text-xs font-medium text-slate-700">{{ __('sales::ui.credit.override_reason') }}</label>
                        <input id="override_reason" name="override_reason" maxlength="255" class="{{ $field }}" placeholder="{{ __('sales::ui.credit.override_hint') }}">
                    </div>
                @endcan
                <x-core::button type="submit">{{ __('sales::ui.actions.confirm') }}</x-core::button>
            </form>
        @endcan
    @endif

    @include('sales::docs.lines', ['lines' => $doc['lines'], 'totals' => $doc['totals'], 'progress' => true])

    @if ($st === 'confirmed')
        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            @if ($deliverable->isNotEmpty())
                @can('sales.delivery.post')
                    <form method="POST" action="{{ route('sales.orders.deliver', $doc['id']) }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        @csrf
                        <h2 class="text-base font-semibold text-slate-900">{{ __('sales::ui.actions.deliver') }}</h2>
                        <p class="text-xs text-slate-500">{{ __('sales::ui.order.deliver_help') }}</p>
                        <x-core::input name="delivery_date" type="date" :label="__('sales::ui.common.date')" :value="$today" />
                        @foreach ($deliverable as $i => $l)
                            <div class="flex items-center gap-2 text-sm">
                                <input type="hidden" name="lines[{{ $i }}][line]" value="{{ $l['line'] }}">
                                <span class="w-6 text-slate-500">{{ $l['line'] }}</span>
                                <span class="flex-1">{{ $l['item'] }} {{ $l['item_name'] }}</span>
                                <input name="lines[{{ $i }}][qty]" value="{{ $num(bcsub($l['qty'], $l['qty_delivered'], 6)) }}" inputmode="decimal" class="{{ $field }} !w-24 text-end">
                                <span class="w-10 text-slate-500">{{ $l['uom'] }}</span>
                            </div>
                        @endforeach
                        <x-core::button type="submit">{{ __('sales::ui.actions.deliver') }}</x-core::button>
                    </form>
                @endcan
            @endif

            @if ($billable->isNotEmpty())
                @can('sales.invoice.post')
                    <form method="POST" action="{{ route('sales.orders.invoice', $doc['id']) }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        @csrf
                        <h2 class="text-base font-semibold text-slate-900">{{ __('sales::ui.actions.invoice') }}</h2>
                        <p class="text-xs text-slate-500">{{ __('sales::ui.order.invoice_help') }}</p>
                        <div class="grid grid-cols-2 gap-3">
                            <x-core::input name="invoice_date" type="date" :label="__('sales::ui.common.date')" :value="$today" />
                            <x-core::input name="due_date" type="date" :label="__('sales::ui.invoice.due')" :hint="__('sales::ui.invoice.due_hint')" />
                        </div>
                        @foreach ($billable as $i => $l)
                            <div class="flex items-center gap-2 text-sm">
                                <input type="hidden" name="lines[{{ $i }}][line]" value="{{ $l['line'] }}">
                                <span class="w-6 text-slate-500">{{ $l['line'] }}</span>
                                <span class="flex-1">{{ $l['item'] }} {{ $l['item_name'] }}</span>
                                <input name="lines[{{ $i }}][qty]" value="{{ $num($l['billable']) }}" inputmode="decimal" class="{{ $field }} !w-24 text-end">
                                <span class="w-10 text-slate-500">{{ $l['uom'] }}</span>
                            </div>
                        @endforeach
                        <x-core::input name="notes" :label="__('sales::ui.form.notes')" />
                        <x-core::button type="submit">{{ __('sales::ui.actions.invoice') }}</x-core::button>
                    </form>
                @endcan
            @endif
        </div>
    @endif

    @if ($doc['deliveries'] || $doc['invoices'])
        <div class="mt-8 grid gap-6 text-sm sm:grid-cols-2">
            <div>
                <h3 class="font-semibold text-slate-900">{{ __('sales::ui.titles.deliveries') }}</h3>
                <ul class="mt-2 space-y-1">@forelse ($doc['deliveries'] as $d) <li><a class="font-mono text-xs text-indigo-600 hover:underline" href="{{ route('sales.deliveries.show', $d['id']) }}">{{ $d['document_no'] }}</a> · {{ $d['date'] }} · {{ __('sales::ui.status.'.$d['status']) }}</li> @empty <li class="text-slate-500">—</li> @endforelse</ul>
            </div>
            <div>
                <h3 class="font-semibold text-slate-900">{{ __('sales::ui.titles.invoices') }}</h3>
                <ul class="mt-2 space-y-1">@forelse ($doc['invoices'] as $i) <li><a class="font-mono text-xs text-indigo-600 hover:underline" href="{{ route('sales.invoices.show', $i['id']) }}">{{ $i['document_no'] }}</a> · {{ $i['date'] }} · <x-core::money :value="$i['gross_total']" /></li> @empty <li class="text-slate-500">—</li> @endforelse</ul>
            </div>
        </div>
    @endif

    @if (in_array($st, ['draft', 'confirmed'], true))
        @can('sales.order.manage')
            <form method="POST" action="{{ route('sales.orders.cancel', $doc['id']) }}" class="mt-8 flex flex-wrap items-end gap-2 text-sm">
                @csrf
                <div>
                    <label for="reason" class="mb-1 block text-xs font-medium text-slate-600">{{ __('sales::ui.common.reason') }}</label>
                    <input id="reason" name="reason" required maxlength="255" class="{{ $field }} !w-72">
                    @error('reason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-core::button type="submit" variant="danger" size="sm">{{ __('sales::ui.actions.cancel_order') }}</x-core::button>
            </form>
        @endcan
    @endif
</x-core::layouts.app>
