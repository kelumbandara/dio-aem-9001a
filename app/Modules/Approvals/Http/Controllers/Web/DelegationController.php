<?php

namespace App\Modules\Approvals\Http\Controllers\Web;

use App\Modules\Approvals\Actions\CreateDelegation;
use App\Modules\Approvals\Http\Requests\StoreDelegationRequest;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Core\Contracts\UserDirectory;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DelegationController extends Controller
{
    public function index(Request $request, UserDirectory $users, ApprovableRegistry $registry): View
    {
        $me = $request->user()->id;
        $rows = ApprovalDelegation::query()
            ->where(fn ($q) => $q->where('delegator_id', $me)->orWhere('delegate_id', $me))
            ->latest('id')->limit(50)->get();

        return view('approvals::delegations', [
            'delegations' => $rows,
            'names' => $users->names($rows->flatMap(fn ($d) => [$d->delegator_id, $d->delegate_id])->unique()->all()),
            'candidates' => array_diff_key($users->activeUsers(), [$me => true]),
            'actions' => $registry->keys(),
            'me' => $me,
            'maxDays' => config('approvals.delegation_max_days'),
        ]);
    }

    public function store(StoreDelegationRequest $request, CreateDelegation $create): RedirectResponse
    {
        $tz = config('core.display_timezone');

        try {
            $create(
                $request->user()->id,
                (int) $request->validated('delegate_id'),
                CarbonImmutable::parse($request->validated('starts_at'), $tz)->utc(),
                CarbonImmutable::parse($request->validated('ends_at'), $tz)->utc(),
                $request->validated('action_key') ?: null,
                $request->validated('reason'),
            );
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors(['delegation' => $e->getMessage()]);
        }

        return back()->with('status', __('approvals::ui.flash.delegation_created'));
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): RedirectResponse
    {
        abort_unless($delegation->delegator_id === $request->user()->id, 403);
        $delegation->forceFill(['revoked_at' => now()])->save();

        return back()->with('status', __('approvals::ui.flash.delegation_revoked'));
    }
}
