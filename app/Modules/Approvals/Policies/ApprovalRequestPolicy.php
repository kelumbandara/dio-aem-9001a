<?php

namespace App\Modules\Approvals\Policies;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApproverResolver;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who can see a request is separate from who can decide it (that is ApproverResolver's job).
 * Gate::before lets `super-admin` view and cancel everything; it does NOT let them approve.
 */
class ApprovalRequestPolicy
{
    public function __construct(private readonly ApproverResolver $approvers) {}

    public function viewAny(Authenticatable&Authorizable $user): bool
    {
        return true; // lists are scoped by the query, not by this check
    }

    public function view(Authenticatable&Authorizable $user, ApprovalRequest $request): bool
    {
        return $user->can('approvals.request.view-all')
            || $this->approvers->isInvolved($request, (int) $user->getAuthIdentifier());
    }

    /** Attachments (evidence, quotes) can be added while the request is still open. */
    public function attach(Authenticatable&Authorizable $user, ApprovalRequest $request): bool
    {
        return $request->isOpen() && $this->view($user, $request);
    }

    public function cancel(Authenticatable&Authorizable $user, ApprovalRequest $request): bool
    {
        return $request->requested_by === (int) $user->getAuthIdentifier() || $user->can('approvals.request.manage');
    }

    public function retry(Authenticatable&Authorizable $user, ApprovalRequest $request): bool
    {
        return $user->can('approvals.request.manage');
    }
}
