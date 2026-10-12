@php $banner = app(\App\Modules\Core\Contracts\LicenceGate::class)->banner(); @endphp
@if ($banner)
    <div role="alert" @class([
        'mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-3 text-sm',
        'border-sky-200 bg-sky-50 text-sky-800' => $banner['level'] === 'info',
        'border-amber-300 bg-amber-50 text-amber-900' => $banner['level'] === 'warning',
        'border-red-300 bg-red-50 text-red-800' => $banner['level'] === 'danger',
    ])>
        <span>{{ $banner['message'] }}</span>
        @if ($banner['url']) <a href="{{ $banner['url'] }}" class="font-medium underline">{{ __('core::ui.licence_banner_link') }}</a> @endif
    </div>
@endif
