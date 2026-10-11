<x-core::layouts.app :title="__('core::ui.company.title')">
    <form method="POST" action="{{ route('company.update') }}" class="max-w-3xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @method('PUT')
        <p class="text-sm text-slate-600">{{ __('core::ui.company.hint') }}</p>
        @error('form') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach (['legal_name' => true, 'trade_name' => false, 'registration_no' => false, 'tax_id' => false, 'address_line1' => false, 'address_line2' => false, 'city' => false, 'country' => false, 'phone' => false, 'email' => false, 'website' => false] as $field => $required)
                <x-core::input :name="$field" :label="__('core::ui.company.'.$field)" :value="old($field, $company?->{$field})" :required="$required" />
            @endforeach
        </div>
        <div class="pt-2"><x-core::button type="submit">{{ __('core::ui.common.save') }}</x-core::button></div>
    </form>
</x-core::layouts.app>
