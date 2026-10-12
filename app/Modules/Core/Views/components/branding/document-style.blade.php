{{-- Colour, font and text size of printed documents (invoices, purchase orders…): one setting restyles them all. --}}
@php($look = app(\App\Modules\Core\Contracts\Appearance::class))
@vite(['resources/css/fonts.css'])
<style>{!! $look->documentCss() !!}</style>
@if ($look->logoUrl()) <link rel="icon" href="{{ $look->logoUrl() }}"> @endif
