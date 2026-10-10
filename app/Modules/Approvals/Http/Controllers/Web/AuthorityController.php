<?php

namespace App\Modules\Approvals\Http\Controllers\Web;

use App\Modules\Approvals\Actions\SaveAuthority;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Core\Contracts\UserDirectory;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthorityController extends Controller
{
    public function index(Request $request, UserDirectory $users, ApprovableRegistry $registry): View
    {
        $this->allow($request);
        $rows = ApprovalAuthority::query()->orderBy('action_key')->orderBy('id')->get();

        return view('approvals::authority', [
            'rows' => $rows,
            'names' => $users->names($rows->pluck('user_id')->filter()->all()),
            'roles' => $users->roleNames(),
            'userChoices' => $users->activeUsers(),
            'actions' => $registry->keys(),
        ]);
    }

    public function store(Request $request, SaveAuthority $save): RedirectResponse
    {
        $this->allow($request);

        $data = $request->validate([
            'holder' => ['required', 'string', 'max:120'], // "role:<name>" or "user:<id>"
            'action_key' => ['required', 'string', 'max:100'],
            'max_amount' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$kind, $value] = array_pad(explode(':', $data['holder'], 2), 2, '');

        try {
            $save([
                'role_name' => $kind === 'role' ? $value : null,
                'user_id' => $kind === 'user' ? (int) $value : null,
                'action_key' => $data['action_key'],
                'max_amount' => ($data['max_amount'] ?? '') === '' ? null : $data['max_amount'],
                'note' => $data['note'] ?? null,
            ]);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors(['authority' => $e->getMessage()]);
        }

        return back()->with('status', __('approvals::ui.flash.authority_saved'));
    }

    public function destroy(Request $request, ApprovalAuthority $authority): RedirectResponse
    {
        $this->allow($request);
        $authority->delete();

        return back()->with('status', __('approvals::ui.flash.authority_deleted'));
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('approvals.authority.manage'), 403);
    }
}
