@php
    $tones = ['draft' => 'neutral', 'sent' => 'info', 'accepted' => 'success', 'converted' => 'success', 'rejected' => 'danger', 'cancelled' => 'danger', 'expired' => 'warning'];
    $raw = $doc['raw_status'];
    $field = 'block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="$doc['document_no']">
    @foreach (['form', 'version'] as $e) @error($e) <x-core::alert type="error" class="mb-4">{{ $message }}</x-core::alert> @enderror @endforeach

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="text-sm text-slate-600">
            <x-core::status-badge :tone="$tones[$doc['status']] ?? 'neutral'">{{ __('sales::ui.status.'.$doc['status']) }}</x-core::status-badge>
            <span class="ms-2">{{ $doc['customer_name'] }} <span class="font-mono text-xs">{{ $doc['customer'] }}</span></span>
            <div class="mt-1">{{ __('sales::ui.common.date') }}: {{ $doc['quote_date'] }} · {{ __('sales::ui.form.valid_until') }}: {{ $doc['valid_until'] }}@if ($doc['reference']) · {{ $doc['reference'] }}@endif</div>
        </div>
        @can('sales.quotation.manage')
            <div class="flex flex-wrap gap-2">
                @if ($raw === 'draft')
                    <a href="{{ route('sales.quotations.edit', $doc['id']) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('sales::ui.common.edit') }}</a>
                @endif
                @foreach (['draft' => ['sent'], 'sent' => ['accepted', 'rejected']] as $from => $moves)
                    @if ($raw === $from)
                        @foreach ($moves as $to)
                            <form method="POST" action="{{ route('sales.quotations.transition', [$doc['id'], $to]) }}">@csrf <x-core::button type="submit" size="sm" :variant="$to === 'rejected' ? 'secondary' : 'primary'">{{ __('sales::ui.actions.'.$to) }}</x-core::button></form>
                        @endforeach
                    @endif
                @endforeach
                @if (in_array($raw, ['draft', 'sent', 'accepted'], true))
                    <form method="POST" action="{{ route('sales.quotations.transition', [$doc['id'], 'cancelled']) }}">@csrf <x-core::button type="submit" size="sm" variant="danger">{{ __('sales::ui.actions.cancelled') }}</x-core::button></form>
                @endif
            </div>
        @endcan
    </div>

    @if ($raw === 'accepted')
        @can('sales.order.manage')
            <form method="POST" action="{{ route('sales.quotations.convert', $doc['id']) }}" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                @csrf
                <div>
                    <label for="warehouse" class="mb-1 block text-xs font-medium text-slate-700">{{ __('sales::ui.form.ship_from') }}</label>
                    <select id="warehouse" name="warehouse" required class="{{ $field }}">
                        @foreach ($warehouses as $id => $label) <option value="{{ explode(' ', $label)[0] }}">{{ $label }}</option> @endforeach
                    </select>
                </div>
                <x-core::button type="submit">{{ __('sales::ui.actions.convert') }}</x-core::button>
                @error('warehouse') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </form>
        @endcan
    @endif

    @include('sales::docs.lines', ['lines' => $doc['lines'], 'totals' => $doc['totals'], 'inclusive' => $doc['prices_include_vat']])
    @if ($doc['notes'] || $doc['terms'])
        <div class="mt-6 grid gap-4 text-sm text-slate-600 sm:grid-cols-2">
            @if ($doc['notes']) <div><div class="font-medium text-slate-800">{{ __('sales::ui.form.notes') }}</div>{{ $doc['notes'] }}</div> @endif
            @if ($doc['terms']) <div><div class="font-medium text-slate-800">{{ __('sales::ui.form.terms') }}</div>{{ $doc['terms'] }}</div> @endif
        </div>
    @endif
</x-core::layouts.app>
