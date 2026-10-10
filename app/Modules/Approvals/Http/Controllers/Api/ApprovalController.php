<?php

namespace App\Modules\Approvals\Http\Controllers\Api;

use App\Modules\Approvals\Actions\ListApprovals;
use App\Modules\Approvals\Http\Requests\DecisionRequest;
use App\Modules\Approvals\Http\Resources\ApprovalResource;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function index(Request $request, ListApprovals $list): AnonymousResourceCollection
    {
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in([ListApprovals::INBOX, ListApprovals::MINE, ListApprovals::ALL])],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'expired', 'cancelled', 'executed', 'execution_failed'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $scope = $data['scope'] ?? ListApprovals::INBOX;
        abort_if($scope === ListApprovals::ALL && ! $request->user()->can('approvals.request.view-all'), 403);

        return ApprovalResource::collection($list($scope, $request->user()->id, (int) ($data['per_page'] ?? 25), $data['status'] ?? null));
    }

    public function show(Request $request, ApprovalRequest $approval): ApprovalResource
    {
        $this->authorize('view', $approval);

        return (new ApprovalResource($approval->load(['steps', 'decisions', 'executions'])))->detailed();
    }

    public function approve(DecisionRequest $request, ApprovalRequest $approval): ApprovalResource
    {
        $this->authorize('view', $approval);
        $result = $this->engine->approve($approval->public_id, $request->user()->id, $request->validated('comment'), 'api', $request->ip());

        return (new ApprovalResource($result->load(['steps', 'decisions', 'executions'])))->detailed();
    }

    public function reject(DecisionRequest $request, ApprovalRequest $approval): ApprovalResource
    {
        $this->authorize('view', $approval);
        $result = $this->engine->reject($approval->public_id, $request->user()->id, (string) $request->validated('comment'), 'api', $request->ip());

        return (new ApprovalResource($result->load(['steps', 'decisions', 'executions'])))->detailed();
    }

    public function cancel(DecisionRequest $request, ApprovalRequest $approval): ApprovalResource
    {
        $result = $this->engine->cancel($approval->public_id, $request->user(), $request->validated('comment'));

        return (new ApprovalResource($result->load(['steps', 'decisions', 'executions'])))->detailed();
    }

    public function retry(Request $request, ApprovalRequest $approval): ApprovalResource
    {
        $this->authorize('retry', $approval);
        $result = $this->engine->retryExecution($approval->public_id, $request->user());

        return (new ApprovalResource($result->load(['steps', 'decisions', 'executions'])))->detailed();
    }
}
