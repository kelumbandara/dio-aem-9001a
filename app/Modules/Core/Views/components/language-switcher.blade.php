@php
    $labels = ['en' => 'EN', 'si' => 'සිං', 'ta' => 'த'];
    $names = ['en' => 'English', 'si' => 'සිංහල', 'ta' => 'தமிழ்'];
@endphp
<form method="POST" action="{{ route('locale.update') }}" class="flex items-center gap-1" aria-label="{{ __('core::ui.language') }}">
    @csrf
    @foreach ($labels as $code => $label)
        <button type="submit" name="locale" value="{{ $code }}" title="{{ $names[$code] }}"
                @class([
                    'rounded-md px-2 py-1 text-xs font-medium',
                    'bg-indigo-600 text-white' => app()->getLocale() === $code,
                    'text-slate-600 hover:bg-slate-100' => app()->getLocale() !== $code,
                ])
                @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</button>
    @endforeach
</form>
