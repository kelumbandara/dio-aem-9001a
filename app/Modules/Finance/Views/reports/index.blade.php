<x-core::layouts.app :title="__('finance::ui.reports.title')">
    <div class="grid max-w-4xl grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach (['trial-balance' => 'trial_balance', 'general-ledger' => 'general_ledger', 'profit-and-loss' => 'profit_loss', 'balance-sheet' => 'balance_sheet'] as $route => $key)
            <a href="{{ route('finance.reports.'.$route) }}" class="block rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-indigo-300">
                <h2 class="text-base font-semibold text-slate-900">{{ __("finance::ui.reports.{$key}") }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __("finance::ui.reports.{$key}_hint") }}</p>
            </a>
        @endforeach
    </div>
</x-core::layouts.app>
