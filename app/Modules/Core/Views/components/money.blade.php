{{-- Formats a decimal STRING (never a float) for display: thousands separators, 2 places, half-up. --}}
@props(['value', 'currency' => 'LKR'])
@php
    $text = null;
    if ($value !== null && preg_match('/^-?\d+(\.\d+)?$/', (string) $value) === 1) {
        $negative = str_starts_with((string) $value, '-');
        $abs = ltrim((string) $value, '-');
        $rounded = bcadd($abs, '0.005', 2);                       // half-up on the absolute value
        [$int, $dec] = explode('.', $rounded);
        $text = ($negative && bccomp($rounded, '0', 2) !== 0 ? '-' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int).'.'.$dec;
    }
@endphp
@if ($text !== null)
    <span {{ $attributes->merge(['class' => 'whitespace-nowrap tabular-nums']) }}>{{ $currency }} {{ $text }}</span>
@else
    <span {{ $attributes->merge(['class' => 'text-slate-400']) }}>—</span>
@endif
