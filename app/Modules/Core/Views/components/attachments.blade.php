{{-- List + upload for any record implementing HasAttachments. $items = list<AttachmentInfo>, $names = id => uploader name. --}}
@props(['items', 'type', 'id', 'canAttach' => false, 'names' => []])
<section {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm']) }}>
    <h2 class="text-base font-semibold text-slate-900">{{ __('core::ui.attachments.title') }}</h2>

    <ul class="mt-3 divide-y divide-slate-100 text-sm">
        @forelse ($items as $item)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <div class="min-w-0">
                    <a href="{{ route('attachments.download', $item->id) }}" class="break-all font-medium text-indigo-700 hover:underline">{{ $item->originalName }}</a>
                    <div class="text-xs text-slate-500">
                        {{ number_format($item->sizeBytes / 1024, 0) }} KB · {{ $names[$item->uploadedBy] ?? '' }} ·
                        <x-core::datetime :value="$item->createdAt" />
                        @if ($item->scanStatus === 'infected') · <span class="text-red-600">{{ $item->scanStatus }}</span> @endif
                    </div>
                </div>
                @if ($canAttach)
                    <form method="POST" action="{{ route('attachments.destroy', $item->id) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-600 hover:underline">{{ __('core::ui.attachments.remove') }}</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="py-2 text-slate-500">{{ __('core::ui.attachments.none') }}</li>
        @endforelse
    </ul>

    @if ($canAttach)
        <form method="POST" action="{{ route('attachments.store') }}" enctype="multipart/form-data" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
            @csrf
            <input type="hidden" name="attachable_type" value="{{ $type }}">
            <input type="hidden" name="attachable_id" value="{{ $id }}">
            <label class="block text-sm font-medium text-slate-700" for="attachment-file">{{ __('core::ui.attachments.upload') }}</label>
            <input id="attachment-file" type="file" name="file" required class="block w-full text-sm text-slate-600 file:me-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200">
            @error('file') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="text-xs text-slate-500">{{ __('core::ui.attachments.hint', ['size' => config('core.attachments.max_kb') / 1024]) }}</p>
            <x-core::button type="submit" size="sm">{{ __('core::ui.attachments.upload') }}</x-core::button>
        </form>
    @endif
</section>
