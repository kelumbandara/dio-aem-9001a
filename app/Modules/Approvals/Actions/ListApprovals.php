<?php

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;
use App\Modules\Approvals\Services\ApproverResolver;
use App\Modules\Core\Contracts\UserDirectory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

final class ListApprovals
{
    public const INBOX = 'inbox';

    public const MINE = 'mine';

    public const ALL = 'all';

    public function __construct(
        private readonly ApproverResolver $approvers,
        private readonly UserDirectory $users,
    ) {}

    /**
     * @param  'inbox'|'mine'|'all'  $scope  `all` is only for callers allowed to view everything
     * @return LengthAwarePaginator<int, ApprovalRequest>
     */
    public function __invoke(string $scope, int $userId, int $perPage = 25, ?string $status = null): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        if ($scope === self::INBOX) {
            return $this->inbox($userId, $perPage);
        }

        return ApprovalRequest::query()
            ->when($scope === self::MINE, fn ($q) => $q->where('requested_by', $userId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Requests waiting for a decision this user may actually give (roles, delegation, authority, four-eyes).
     *
     * @return LengthAwarePaginator<int, ApprovalRequest>
     */
    private function inbox(int $userId, int $perPage): LengthAwarePaginator
    {
        $principalIds = [$userId, ...ApprovalDelegation::query()->active()->where('delegate_id', $userId)->pluck('delegator_id')->map(fn ($id) => (int) $id)->all()];
        $roles = array_values(array_unique(array_merge(...array_map(fn (int $id) => $this->users->rolesOf($id), $principalIds))));

        $candidates = ApprovalRequest::query()
            ->where('status', ApprovalRequest::PENDING)
            ->whereHas('steps', fn ($q) => $q->where('status', ApprovalRequestStep::PENDING)
                ->where(fn ($w) => $w->whereIn('approver_user_id', $principalIds)->orWhereIn('approver_role', $roles)))
            ->with('steps')
            ->orderBy('expires_at')
            ->get();

        $mine = $candidates->filter(fn (ApprovalRequest $request) => $request->steps
            ->where('status', ApprovalRequestStep::PENDING)
            ->contains(fn (ApprovalRequestStep $step) => $this->approvers->deciderFor($request, $step, $userId) !== null))
            ->values();

        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $mine->forPage($page, $perPage)->values(),
            $mine->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
