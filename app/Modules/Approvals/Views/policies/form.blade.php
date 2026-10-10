@php
    $editing = $policy->exists;
    $oldSteps = old('steps');
    $initialSteps = $oldSteps ?? ($editing
        ? $policy->steps->map(fn ($s) => ['order' => $s->step_order, 'type' => $s->approver_type, 'role' => $s->approver_role, 'user_id' => $s->approver_user_id, 'label' => $s->label])->values()->all()
        : [['order' => 1, 'type' => 'role', 'role' => '', 'user_id' => '', 'label' => '']]);
    $risks = old('risk_levels', $policy->risk_levels ?? []);
    $select = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="$editing ? __('approvals::ui.policies.edit') : __('approvals::ui.policies.new')">
    <form method="POST" action="{{ $editing ? route('approvals.policies.update', $policy) : route('approvals.policies.store') }}" class="max-w-3xl space-y-6">
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <x-core::input name="name" :label="__('approvals::ui.policies.name')" :value="old('name', $policy->name)" required maxlength="255" />
            <div>
                <label for="action_key" class="mb-1 block text-sm font-medium text-slate-700">{{ __('approvals::ui.policies.action') }}</label>
                <select id="action_key" name="action_key" required class="{{ $select }}">
                    <option value="">—</option>
                    @foreach ($actions as $key)
                        <option value="{{ $key }}" @selected(old('action_key', $policy->action_key) === $key)>{{ $key }}</option>
                    @endforeach
                </select>
                @if ($actions === []) <p class="mt-1 text-xs text-amber-700">{{ __('approvals::ui.policies.no_actions') }}</p> @endif
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-core::input name="min_amount" inputmode="decimal" :label="__('approvals::ui.policies.min_amount')" :value="old('min_amount', $policy->min_amount)" />
                <x-core::input name="max_amount" inputmode="decimal" :label="__('approvals::ui.policies.max_amount')" :value="old('max_amount', $policy->max_amount)" />
            </div>
            <fieldset>
                <legend class="mb-1 text-sm font-medium text-slate-700">{{ __('approvals::ui.policies.risk') }}</legend>
                <div class="flex gap-4 text-sm">
                    @foreach (['R1', 'R2', 'R3'] as $r)
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="risk_levels[]" value="{{ $r }}" @checked(in_array($r, (array) $risks, true)) class="rounded border-slate-300 text-indigo-600"> {{ $r }}</label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ __('approvals::ui.policies.risk_hint') }}</p>
            </fieldset>
        </section>

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ steps: {{ \Illuminate\Support\Js::from($initialSteps) }} }">
            <h2 class="text-base font-semibold text-slate-900">{{ __('approvals::ui.policies.steps') }}</h2>
            <p class="text-xs text-slate-500">{{ __('approvals::ui.policies.steps_hint') }}</p>
            @error('steps') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <template x-for="(step, i) in steps" :key="i">
                <div class="grid grid-cols-12 gap-3 rounded-lg border border-slate-200 p-3">
                    <div class="col-span-4 sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('approvals::ui.policies.order') }}</label>
                        <input type="number" min="1" max="20" :name="`steps[${i}][order]`" x-model="step.order" class="{{ $select }}" required>
                    </div>
                    <div class="col-span-8 sm:col-span-3">
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('approvals::ui.policies.approver_type') }}</label>
                        <select :name="`steps[${i}][type]`" x-model="step.type" class="{{ $select }}">
                            <option value="role">{{ __('approvals::ui.policies.by_role') }}</option>
                            <option value="user">{{ __('approvals::ui.policies.by_user') }}</option>
                        </select>
                    </div>
                    <div class="col-span-12 sm:col-span-4">
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('approvals::ui.policies.approver') }}</label>
                        <select x-show="step.type === 'role'" :name="step.type === 'role' ? `steps[${i}][role]` : ''" x-model="step.role" class="{{ $select }}">
                            <option value="">—</option>
                            @foreach ($roles as $role) <option value="{{ $role }}">{{ $role }}</option> @endforeach
                        </select>
                        <select x-show="step.type === 'user'" :name="step.type === 'user' ? `steps[${i}][user_id]` : ''" x-model="step.user_id" class="{{ $select }}">
                            <option value="">—</option>
                            @foreach ($userChoices as $id => $name) <option value="{{ $id }}">{{ $name }}</option> @endforeach
                        </select>
                    </div>
                    <div class="col-span-9 sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ __('approvals::ui.policies.label') }}</label>
                        <input type="text" maxlength="100" :name="`steps[${i}][label]`" x-model="step.label" class="{{ $select }}">
                    </div>
                    <div class="col-span-3 flex items-end justify-end sm:col-span-1">
                        <button type="button" class="text-sm text-red-600 hover:underline" x-show="steps.length > 1" @click="steps.splice(i, 1)">{{ __('approvals::ui.policies.remove_step') }}</button>
                    </div>
                </div>
            </template>
            <x-core::button type="button" variant="secondary" size="sm" @click="steps.push({ order: Math.max(...steps.map(s => Number(s.order) || 1)) + 1, type: 'role', role: '', user_id: '', label: '' })">{{ __('approvals::ui.policies.add_step') }}</x-core::button>
        </section>

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-core::input name="priority" type="number" :label="__('approvals::ui.policies.priority')" :value="old('priority', $policy->priority)" required />
                <x-core::input name="expiry_hours" type="number" :label="__('approvals::ui.policies.expiry_hours')" :value="old('expiry_hours', $policy->expiry_hours)" required />
                <x-core::input name="reminder_after_hours" type="number" :label="__('approvals::ui.policies.reminder_hours')" :value="old('reminder_after_hours', $policy->reminder_after_hours)" required />
            </div>
            <div class="space-y-2 text-sm">
                @foreach (['distinct_approvers' => 'distinct', 'allow_self_approval' => 'self', 'is_active' => 'active'] as $field => $label)
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="{{ $field }}" value="0">
                        <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $policy->$field)) class="mt-0.5 rounded border-slate-300 text-indigo-600">
                        <span>{{ __('approvals::ui.policies.'.$label) }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        <div class="flex gap-3">
            <x-core::button type="submit">{{ __('approvals::ui.policies.save') }}</x-core::button>
            <a href="{{ route('approvals.policies.index') }}"><x-core::button type="button" variant="secondary">{{ __('core::ui.common.cancel') }}</x-core::button></a>
        </div>
    </form>
</x-core::layouts.app>
