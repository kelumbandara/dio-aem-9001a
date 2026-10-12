@php($look = app(\App\Modules\Core\Contracts\Appearance::class))
@if ($look->documentFooter()) <div class="legal" style="margin-bottom: 4px;">{{ $look->documentFooter() }}</div> @endif
@if ($look->poweredBy()) <div class="legal" style="opacity: .7;">{{ $look->poweredBy() }}</div> @endif
