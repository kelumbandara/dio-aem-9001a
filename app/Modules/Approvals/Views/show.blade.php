@php
    $tone = ['pending' => 'warning', 'approved' => 'info', 'executed' => 'success', 'rejected' => 'danger', 'execution_failed' => 'danger'];
    $stepTone = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
    $flat = function (array $data, string $prefix = '') use (&$flat): array {
        $rows = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v)) { $rows += $flat($v, $key); } else { $rows[$key] = is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? ''); }
        }
        return $rows;
    };
@endphp
<x-core::layouts.app :title="$approval->document_no">
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-lg font-semibold text-slate-900">{{ $approval->summary }}</p>
                        <p class="mt-1 font-mono text-xs text-slate-500">{{ $approval->action_key }}</p>
                    </div>
                    <x-core::status-badge :tone="$tone[$approval->status] ?? 'neutral'">{{ __('approvals::ui.status.'.$approval->status) }}</x-core::status-badge>
                </div>
                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">{{ __('approvals::ui.col.amount') }}</dt><dd class="font-medium"><x-core::money :value="$approval->amount" /></dd></div>
                    <div><dt class="text-slate-500">{{ __('approvals::ui.col.requested_by') }}</dt><dd class="font-medium">{{ $names[$approval->requested_by] ?? '' }} <span class="text-xs font-normal text-slate-400">({{ $approval->channel }})</span></dd></div>
                    <div><dt class="text-slate-500">{{ __('approvals::ui.show.requested_at') }}</dt><dd><x-core::datetime :value="$approval->created_at" /></dd></div>
                    <div><dt class="text-slate-500">{{ __('approvals::ui.col.expires') }}</dt><dd><x-core::datetime :value="$approval->expires_at" /></dd></div>
                </dl>
            </section>

            @if ($canDecide)
                <section class="rounded-xl border border-indigo-200 bg-indigo-50/50 p-5 shadow-sm" aria-labelledby="decide-heading">
                    <h2 id="decide-heading" class="text-base font-semibold text-slate-900">{{ __('approvals::ui.show.your_decision') }}</h2>
                    <form method="POST" action="{{ route('approvals.approve', $approval) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label for="comment" class="mb-1 block text-sm font-medium text-slate-700">{{ __('approvals::ui.show.comment') }}</label>
                            <textarea id="comment" name="comment" rows="3" maxlength="2000" class="block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600">{{ old('comment') }}</textarea>
                            <p class="mt-1 text-xs text-slate-500">{{ __('approvals::ui.show.comment_hint') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <x-core::button type="submit" formaction="{{ route('approvals.approve', $approval) }}">{{ __('approvals::ui.show.approve') }}</x-core::button>
                            <x-core::button type="submit" variant="danger" formaction="{{ route('approvals.reject', $approval) }}">{{ __('approvals::ui.show.reject') }}</x-core::button>
                        </div>
                    </form>
                </section>
            @endif

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('approvals::ui.show.steps') }}</h2>
                <ol class="mt-3 space-y-3 text-sm">
                    @foreach ($approval->steps as $step)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <span class="font-medium">{{ __('approvals::ui.show.step', ['n' => $step->step_order]) }}</span>
                                <span class="text-slate-600">
                                    — {{ $step->approver_type === 'role' ? __('approvals::ui.show.role', ['role' => $step->approver_role]) : ($names[$step->approver_user_id] ?? '') }}
                                    @if ($step->label) ({{ $step->label }}) @endif
                                </span>
                                @if ($step->decided_by)
                                    <div class="text-xs text-slate-500">{{ $names[$step->decided_by] ?? '' }} · <x-core::datetime :value="$step->decided_at" /></div>
                                @endif
                            </div>
                            <x-core::status-badge :tone="$stepTone[$step->status] ?? 'neutral'">{{ __('approvals::ui.step_status.'.$step->status) }}</x-core::status-badge>
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($approval->decisions->isNotEmpty())
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('approvals::ui.show.decisions') }}</h2>
                    <ul class="mt-3 divide-y divide-slate-100 text-sm">
                        @foreach ($approval->decisions as $d)
                            <li class="py-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-core::status-badge :tone="$d->decision === 'approve' ? 'success' : 'danger'">{{ __('approvals::ui.decision.'.$d->decision) }}</x-core::status-badge>
                                    <span class="font-medium">{{ $names[$d->decided_by] ?? '' }}</span>
                                    @if ($d->on_behalf_of) <span class="text-xs text-slate-500">{{ __('approvals::ui.show.on_behalf_of', ['name' => $names[$d->on_behalf_of] ?? '']) }}</span> @endif
                                    <span class="text-xs text-slate-400"><x-core::datetime :value="$d->decided_at" /></span>
                                </div>
                                @if ($d->comment) <p class="mt-1 whitespace-pre-line text-slate-700">{{ $d->comment }}</p> @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($approval->executions->isNotEmpty())
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('approvals::ui.show.execution') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($approval->executions as $e)
                            <li class="flex flex-wrap items-center gap-2">
                                <x-core::status-badge :tone="$e->status === 'succeeded' ? 'success' : 'danger'">{{ __('approvals::ui.exec.'.$e->status) }}</x-core::status-badge>
                                <span class="text-xs text-slate-500">#{{ $e->attempt }} · <x-core::datetime :value="$e->executed_at" /></span>
                                @if ($e->error_message) <span class="text-red-700">{{ $e->error_message }}</span> @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <div class="space-y-6">
            @if ($canCancel || $canRetry)
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap gap-3">
                        @if ($canRetry)
                            <form method="POST" action="{{ route('approvals.retry', $approval) }}">@csrf<x-core::button type="submit" size="sm">{{ __('approvals::ui.show.retry') }}</x-core::button></form>
                        @endif
                        @if ($canCancel)
                            <form method="POST" action="{{ route('approvals.cancel', $approval) }}">@csrf<x-core::button type="submit" variant="secondary" size="sm">{{ __('approvals::ui.show.cancel') }}</x-core::button></form>
                        @endif
                    </div>
                </section>
            @endif

            <x-core::attachments :items="$attachments" type="approval_request" :id="$approval->public_id" :can-attach="$canAttach"
                                 :names="$attachmentUploaders->names(array_map(fn ($a) => $a->uploadedBy, $attachments))" />

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('approvals::ui.show.details') }}</h2>
                <dl class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($flat($approval->payload) as $key => $value)
                        <div class="grid grid-cols-3 gap-2 py-1.5"><dt class="col-span-1 break-words text-slate-500">{{ $key }}</dt><dd class="col-span-2 break-words">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-3 break-all font-mono text-[11px] text-slate-400">SHA-256 {{ $approval->payload_hash }}</p>
            </section>
        </div>
    </div>
</x-core::layouts.app>
