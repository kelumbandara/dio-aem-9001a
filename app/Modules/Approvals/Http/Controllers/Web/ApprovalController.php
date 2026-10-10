<?php

namespace App\Modules\Approvals\Http\Controllers\Web;

use App\Modules\Approvals\Actions\ListApprovals;
use App\Modules\Approvals\Http\Requests\DecisionRequest;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Approvals\Services\ApproverResolver;
use App\Modules\Core\Contracts\AttachmentStore;
use App\Modules\Core\Contracts\UserDirectory;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function index(Request $request, ListApprovals $list, UserDirectory $users): View
    {
        $scope = (string) $request->query('scope', ListApprovals::INBOX);
        abort_unless(in_array($scope, [ListApprovals::INBOX, ListApprovals::MINE, ListApprovals::ALL], true), 404);
        abort_if($scope === ListApprovals::ALL && ! $request->user()->can('approvals.request.view-all'), 403);

        $page = $list($scope, $request->user()->id, 20);

        return view('approvals::index', [
            'scope' => $scope,
            'requests' => $page,
            'names' => $users->names($page->getCollection()->pluck('requested_by')->all()),
            'canViewAll' => $request->user()->can('approvals.request.view-all'),
        ]);
    }

    public function show(Request $request, ApprovalRequest $approval, ApproverResolver $approvers, UserDirectory $users, AttachmentStore $attachments): View
    {
        $this->authorize('view', $approval);
        $approval->load(['steps', 'decisions', 'executions']);
        $userId = $request->user()->id;

        $canDecide = $approval->status === ApprovalRequest::PENDING && $approval->steps
            ->where('status', ApprovalRequestStep::PENDING)
            ->contains(fn ($step) => $approvers->deciderFor($approval, $step, $userId) !== null);

        $ids = array_filter([
            $approval->requested_by,
            ...$approval->steps->pluck('approver_user_id')->all(),
            ...$approval->steps->pluck('decided_by')->all(),
            ...$approval->decisions->pluck('decided_by')->all(),
            ...$approval->decisions->pluck('on_behalf_of')->all(),
        ]);

        return view('approvals::show', [
            'approval' => $approval,
            'names' => $users->names($ids),
            'canDecide' => $canDecide,
            'canCancel' => $request->user()->can('cancel', $approval) && $approval->isOpen(),
            'canRetry' => $request->user()->can('retry', $approval) && $approval->status === ApprovalRequest::EXECUTION_FAILED,
            'canAttach' => $request->user()->can('attach', $approval),
            'attachments' => $attachments->list($approval),
            'attachmentUploaders' => $users,
        ]);
    }

    public function approve(DecisionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        return $this->act($approval, fn () => $this->engine->approve($approval->public_id, $request->user()->id, $request->validated('comment'), 'web', $request->ip()), 'approved');
    }

    public function reject(DecisionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        return $this->act($approval, fn () => $this->engine->reject($approval->public_id, $request->user()->id, (string) $request->validated('comment'), 'web', $request->ip()), 'rejected');
    }

    public function cancel(DecisionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        return $this->act($approval, fn () => $this->engine->cancel($approval->public_id, $request->user(), $request->validated('comment')), 'cancelled');
    }

    public function retry(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('retry', $approval);

        return $this->act($approval, fn () => $this->engine->retryExecution($approval->public_id, $request->user()), 'retried');
    }

    /** @param  Closure(): mixed  $action */
    private function act(ApprovalRequest $approval, Closure $action, string $done): RedirectResponse
    {
        $this->authorize('view', $approval);

        try {
            $action();
        } catch (BusinessException $e) {
            return redirect()->route('approvals.show', $approval)->withErrors(['approval' => $e->getMessage()]);
        }

        return redirect()->route('approvals.show', $approval)->with('status', __("approvals::ui.flash.{$done}"));
    }
}
