<?php

namespace App\Modules\Approvals\Http\Controllers\Web;

use App\Modules\Approvals\Actions\SavePolicy;
use App\Modules\Approvals\Http\Requests\SavePolicyRequest;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Core\Contracts\UserDirectory;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PolicyController extends Controller
{
    public function index(Request $request): View
    {
        $this->allow($request);

        return view('approvals::policies.index', [
            'policies' => ApprovalPolicy::query()->with('steps')->orderBy('action_key')->orderBy('priority')->get(),
        ]);
    }

    public function create(Request $request, ApprovableRegistry $registry, UserDirectory $users): View
    {
        $this->allow($request);

        return $this->form(new ApprovalPolicy([
            'priority' => 100, 'expiry_hours' => 72, 'reminder_after_hours' => 24,
            'allow_self_approval' => false, 'distinct_approvers' => true, 'is_active' => true,
        ]), $registry, $users);
    }

    public function store(SavePolicyRequest $request, SavePolicy $save): RedirectResponse
    {
        try {
            $save($request->payload());
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors(['policy' => $e->getMessage()]);
        }

        return redirect()->route('approvals.policies.index')->with('status', __('approvals::ui.flash.policy_saved'));
    }

    public function edit(Request $request, ApprovalPolicy $policy, ApprovableRegistry $registry, UserDirectory $users): View
    {
        $this->allow($request);

        return $this->form($policy->load('steps'), $registry, $users);
    }

    public function update(SavePolicyRequest $request, ApprovalPolicy $policy, SavePolicy $save): RedirectResponse
    {
        try {
            $save($request->payload(), $policy);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors(['policy' => $e->getMessage()]);
        }

        return redirect()->route('approvals.policies.index')->with('status', __('approvals::ui.flash.policy_saved'));
    }

    /** Soft delete: requests already made keep their own snapshot of the policy. */
    public function destroy(Request $request, ApprovalPolicy $policy): RedirectResponse
    {
        $this->allow($request);
        $policy->delete();

        return redirect()->route('approvals.policies.index')->with('status', __('approvals::ui.flash.policy_deleted'));
    }

    private function form(ApprovalPolicy $policy, ApprovableRegistry $registry, UserDirectory $users): View
    {
        return view('approvals::policies.form', [
            'policy' => $policy,
            'actions' => $registry->keys(),
            'roles' => $users->roleNames(),
            'userChoices' => $users->activeUsers(),
        ]);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('approvals.policy.manage'), 403);
    }
}
