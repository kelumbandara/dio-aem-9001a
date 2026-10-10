@props(['type' => 'info'])
@php
    $classes = [
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'error' => 'border-red-200 bg-red-50 text-red-800',
        'info' => 'border-sky-200 bg-sky-50 text-sky-800',
    ][$type] ?? '';
@endphp
<div role="{{ $type === 'error' ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => "rounded-lg border px-4 py-3 text-sm {$classes}"]) }}>{{ $slot }}</div>
