@props(['variant' => 'primary', 'size' => 'md', 'type' => 'button'])
@php
    $classes = [
        'primary' => 'bg-indigo-600 text-white hover:bg-indigo-500 focus-visible:outline-indigo-600',
        'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:outline-slate-400',
        'danger' => 'bg-red-600 text-white hover:bg-red-500 focus-visible:outline-red-600',
    ][$variant] ?? '';
    $sizes = ['sm' => 'px-3 py-1.5 text-sm', 'md' => 'px-4 py-2 text-sm'][$size] ?? '';
@endphp
<button type="{{ $type }}" {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-lg font-medium shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50 {$classes} {$sizes}"]) }}>{{ $slot }}</button>
