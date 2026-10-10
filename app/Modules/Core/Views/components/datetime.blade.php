{{-- Stored in UTC, shown in the display timezone (Asia/Colombo). --}}
@props(['value'])
@if ($value)
    <time datetime="{{ $value->toIso8601String() }}" title="{{ $value->copy()->utc()->format('Y-m-d H:i:s') }} UTC">{{ $value->copy()->timezone(config('core.display_timezone'))->format('Y-m-d H:i') }}</time>
@endif
