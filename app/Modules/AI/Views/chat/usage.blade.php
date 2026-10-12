<x-core::layouts.app :title="__('ai::ui.menu.usage')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('ai::ui.usage_intro', ['model' => $model]) }}</p>
    @unless ($configured)
        <x-core::alert type="error">{{ __('ai::ui.not_configured') }}</x-core::alert>
    @endunless
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-core::kpi-card :label="__('ai::ui.spent_company')" :value="'USD '.$company" :hint="__('ai::ui.limit', ['amount' => $companyBudget])" />
        <x-core::kpi-card :label="__('ai::ui.limit_person')" :value="'USD '.$userBudget" />
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('ai::ui.person') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('ai::ui.questions') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('ai::ui.tokens') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('ai::ui.cost') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3">{{ $r['name'] }}</td>
                    <td class="px-4 py-3 text-end tabular-nums">{{ $r['questions'] }}</td>
                    <td class="px-4 py-3 text-end tabular-nums">{{ number_format($r['input_tokens']) }} / {{ number_format($r['output_tokens']) }}</td>
                    <td class="px-4 py-3 text-end tabular-nums">USD {{ $r['cost_usd'] }}</td>
                </tr>
            @empty
                <tr><td class="px-4 py-6 text-center text-slate-500" colspan="4">{{ __('ai::ui.nothing') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
</x-core::layouts.app>
