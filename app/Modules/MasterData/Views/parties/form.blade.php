@php
    $select = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $editing = $party !== null;
    $contacts = old('contacts', $editing ? $party->contacts->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'job_title' => $c->job_title, 'email' => $c->email, 'phone' => $c->phone, 'is_primary' => $c->is_primary])->all() : [['name' => '', 'is_primary' => true]]);
    $addresses = old('addresses', $editing ? $party->addresses->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'line1' => $a->line1, 'line2' => $a->line2, 'city' => $a->city, 'district' => $a->district, 'postal_code' => $a->postal_code, 'country' => $a->country, 'is_default' => $a->is_default])->all() : [['type' => 'billing', 'country' => 'LK', 'is_default' => true]]);
    $currentExtra = $editing ? ($slug === 'customers' ? $party->credit_limit : $party->lead_time_days) : null;
@endphp
<x-core::layouts.app :title="$editing ? __('masterdata::ui.parties.edit_title', ['code' => $party->code]) : __('masterdata::ui.parties.'.$slug.'.new')">
    <form method="POST" action="{{ $editing ? route($slug.'.update', $party) : route($slug.'.store') }}" class="max-w-4xl space-y-6" x-data="{ contacts: @js(array_values($contacts)), addresses: @js(array_values($addresses)) }">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $party->version }}"> @endif

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($editing)
                    <div><span class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.common.code') }}</span><p class="py-2 font-mono text-sm">{{ $party->code }}</p></div>
                @else
                    <x-core::input name="code" :label="__('masterdata::ui.common.code')" :hint="__('masterdata::ui.common.auto_code')" :value="old('code')" maxlength="30" />
                @endif
                <x-core::input name="name" :label="__('masterdata::ui.common.name')" :value="old('name', $party?->name)" required maxlength="255" />
                <x-core::input name="legal_name" :label="__('masterdata::ui.parties.legal_name')" :value="old('legal_name', $party?->legal_name)" maxlength="255" />
                <div>
                    <label for="f-kind" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.parties.kind') }}</label>
                    <select id="f-kind" name="kind" class="{{ $select }}">
                        @foreach (['company', 'individual'] as $k) <option value="{{ $k }}" @selected(old('kind', $party?->kind ?? 'company') === $k)>{{ __("masterdata::ui.parties.kinds.{$k}") }}</option> @endforeach
                    </select>
                </div>
                <x-core::input name="tax_id" :label="__('masterdata::ui.parties.tax_id')" :value="old('tax_id', $party?->tax_id)" maxlength="40" />
                <x-core::input name="business_reg_no" :label="__('masterdata::ui.parties.business_reg_no')" :value="old('business_reg_no', $party?->business_reg_no)" maxlength="40" />
                <x-core::input name="nic" :label="__('masterdata::ui.parties.nic')" :value="old('nic', $party?->nic)" maxlength="20" />
                <x-core::input name="phone" :label="__('masterdata::ui.parties.phone')" :value="old('phone', $party?->phone)" maxlength="30" />
                <x-core::input name="email" type="email" :label="__('masterdata::ui.parties.email')" :value="old('email', $party?->email)" maxlength="255" />
                <x-core::input name="website" :label="__('masterdata::ui.parties.website')" :value="old('website', $party?->website)" maxlength="255" />
            </div>
        </section>

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.parties.trading') }}</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="f-currency" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.parties.currency') }}</label>
                    <select id="f-currency" name="currency" class="{{ $select }}"><option value="">—</option>
                        @foreach ($currencies as $c) <option value="{{ $c->code }}" @selected(old('currency', $party?->currency?->code) === $c->code)>{{ $c->code }}</option> @endforeach
                    </select>
                    @error('currency') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-payment_terms" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.parties.payment_terms') }}</label>
                    <select id="f-payment_terms" name="payment_terms" class="{{ $select }}"><option value="">—</option>
                        @foreach ($terms as $t) <option value="{{ $t->code }}" @selected(old('payment_terms', $party?->paymentTerms?->code) === $t->code)>{{ $t->code }} — {{ $t->name }}</option> @endforeach
                    </select>
                    @error('payment_terms') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-price_list" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.parties.price_list') }}</label>
                    <select id="f-price_list" name="price_list" class="{{ $select }}"><option value="">—</option>
                        @foreach ($priceLists as $p) <option value="{{ $p->code }}" @selected(old('price_list', $party?->priceList?->code) === $p->code)>{{ $p->code }} — {{ $p->name }}</option> @endforeach
                    </select>
                    @error('price_list') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @if ($slug === 'customers')
                    <x-core::input name="credit_limit" inputmode="decimal" :label="__('masterdata::ui.parties.credit_limit')" :hint="__('masterdata::ui.parties.credit_limit_hint')" :value="old('credit_limit', $currentExtra)" />
                @else
                    <x-core::input name="lead_time_days" type="number" min="0" :label="__('masterdata::ui.parties.lead_time')" :value="old('lead_time_days', $currentExtra)" />
                @endif
                <div class="sm:col-span-2 pt-6">
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old('is_active', $party?->is_active ?? true))> {{ __('masterdata::ui.common.active') }}</label>
                </div>
            </div>
            <div>
                <label for="f-notes" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.parties.notes') }}</label>
                <textarea id="f-notes" name="notes" rows="2" maxlength="5000" class="{{ $select }}">{{ old('notes', $party?->notes) }}</textarea>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.parties.contacts') }}</h2>
            <div class="mt-3 space-y-3">
                <template x-for="(row, i) in contacts" :key="i">
                    <div class="grid grid-cols-12 items-center gap-2">
                        <input type="hidden" :name="`contacts[${i}][id]`" :value="row.id ?? ''">
                        <input :name="`contacts[${i}][name]`" x-model="row.name" placeholder="{{ __('masterdata::ui.common.name') }}" class="{{ $select }} col-span-3">
                        <input :name="`contacts[${i}][job_title]`" x-model="row.job_title" placeholder="{{ __('masterdata::ui.parties.job_title') }}" class="{{ $select }} col-span-2">
                        <input :name="`contacts[${i}][phone]`" x-model="row.phone" placeholder="{{ __('masterdata::ui.parties.phone') }}" class="{{ $select }} col-span-2">
                        <input :name="`contacts[${i}][email]`" x-model="row.email" placeholder="{{ __('masterdata::ui.parties.email') }}" class="{{ $select }} col-span-3">
                        <input type="hidden" :name="`contacts[${i}][is_primary]`" value="0">
                        <label class="col-span-1 flex items-center gap-1 text-xs"><input type="checkbox" :name="`contacts[${i}][is_primary]`" value="1" x-model="row.is_primary"> {{ __('masterdata::ui.parties.primary') }}</label>
                        <button type="button" class="col-span-1 text-sm text-red-600 hover:underline" @click="contacts.splice(i, 1)" aria-label="{{ __('masterdata::ui.common.remove') }}">✕</button>
                    </div>
                </template>
            </div>
            <button type="button" class="mt-3 text-sm text-indigo-600 hover:underline" @click="contacts.push({ name: '', is_primary: false })">+ {{ __('masterdata::ui.parties.add_contact') }}</button>
            @error('contacts.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.parties.addresses') }}</h2>
            <div class="mt-3 space-y-3">
                <template x-for="(row, i) in addresses" :key="i">
                    <div class="grid grid-cols-12 items-center gap-2 border-b border-slate-100 pb-3">
                        <input type="hidden" :name="`addresses[${i}][id]`" :value="row.id ?? ''">
                        <select :name="`addresses[${i}][type]`" x-model="row.type" class="{{ $select }} col-span-2">
                            @foreach (['billing', 'shipping', 'other'] as $t) <option value="{{ $t }}">{{ __("masterdata::ui.parties.address_types.{$t}") }}</option> @endforeach
                        </select>
                        <input :name="`addresses[${i}][line1]`" x-model="row.line1" placeholder="{{ __('masterdata::ui.parties.line1') }}" class="{{ $select }} col-span-5">
                        <input :name="`addresses[${i}][line2]`" x-model="row.line2" placeholder="{{ __('masterdata::ui.parties.line2') }}" class="{{ $select }} col-span-4">
                        <span class="col-span-1"></span>
                        <input :name="`addresses[${i}][city]`" x-model="row.city" placeholder="{{ __('masterdata::ui.parties.city') }}" class="{{ $select }} col-span-3 col-start-3">
                        <input :name="`addresses[${i}][district]`" x-model="row.district" placeholder="{{ __('masterdata::ui.parties.district') }}" class="{{ $select }} col-span-3">
                        <input :name="`addresses[${i}][postal_code]`" x-model="row.postal_code" placeholder="{{ __('masterdata::ui.parties.postal_code') }}" class="{{ $select }} col-span-2">
                        <input type="hidden" :name="`addresses[${i}][country]`" :value="row.country ?? 'LK'">
                        <input type="hidden" :name="`addresses[${i}][is_default]`" value="0">
                        <label class="col-span-1 flex items-center gap-1 text-xs"><input type="checkbox" :name="`addresses[${i}][is_default]`" value="1" x-model="row.is_default"> {{ __('masterdata::ui.parties.default') }}</label>
                        <button type="button" class="col-span-1 text-sm text-red-600 hover:underline" @click="addresses.splice(i, 1)" aria-label="{{ __('masterdata::ui.common.remove') }}">✕</button>
                    </div>
                </template>
            </div>
            <button type="button" class="mt-3 text-sm text-indigo-600 hover:underline" @click="addresses.push({ type: 'billing', country: 'LK', is_default: false })">+ {{ __('masterdata::ui.parties.add_address') }}</button>
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('masterdata::ui.common.save') }}</x-core::button>
            <a href="{{ $editing ? route($slug.'.show', $party) : route($slug.'.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('masterdata::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
