@props(['class' => 'doc-logo'])
@php($logo = app(\App\Modules\Core\Contracts\Appearance::class)->logoUrl())
@if ($logo)
    <img src="{{ $logo }}" alt="" class="{{ $class }}" style="max-height: 18mm; max-width: 55mm; object-fit: contain; display: block; margin-bottom: 6px;">
@endif
