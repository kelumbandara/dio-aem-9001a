<x-core::layouts.app title="Approval demo">
    <x-core::alert type="info" class="mb-6 max-w-2xl">
        Development demo – not part of the product. It shows the approval flow with a made-up expense. A policy for
        <span class="font-mono">demo.expense.release</span> (from LKR 1,000, approved by the role <em>finance-approver</em>) is created by the demo seeder.
        Requests below the threshold are released immediately.
    </x-core::alert>

    <form method="POST" action="{{ route('approvals.demo.store') }}" class="max-w-xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-core::input name="description" label="What is the expense for?" :value="old('description', 'Office chairs')" required maxlength="150" />
        <x-core::input name="amount" inputmode="decimal" label="Amount (LKR)" :value="old('amount', '25000.00')" required />
        @error('demo') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <x-core::button type="submit">Request approval</x-core::button>
    </form>
</x-core::layouts.app>
