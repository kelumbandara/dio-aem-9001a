@props(['name', 'label', 'type' => 'text', 'hint' => null])
@php $id = 'f-'.$name; @endphp
<div>
    <label for="{{ $id }}" class="mb-1 block text-sm font-medium text-slate-700">{{ $label }}</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           {{ $attributes->merge(['class' => 'block w-full rounded-lg border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm '.($errors->has($name) ? 'ring-red-400' : 'ring-slate-300')]) }}
           @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
    @if ($hint && ! $errors->has($name))
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
