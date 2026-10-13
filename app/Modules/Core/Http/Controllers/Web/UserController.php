<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Actions\Users\ResetUserMfa;
use App\Modules\Core\Actions\Users\SaveUser;
use App\Modules\Core\Actions\Users\SendPasswordLink;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Models\User;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/** People who can sign in (internal users): who they are, which roles they have, switching them off, password links, MFA reset. */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->allow($request, 'core.user.view');
        $q = trim((string) $request->query('q', ''));
        $users = User::query()->where('user_type', User::TYPE_INTERNAL)->with('roles:id,name')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('core::users.index', ['users' => $users, 'q' => $q]);
    }

    public function create(Request $request): View
    {
        $this->allow($request, 'core.user.manage');

        return view('core::users.form', ['user' => null, 'roles' => $this->assignableRoles($request->user()), 'mine' => []]);
    }

    public function store(Request $request, SaveUser $save): RedirectResponse
    {
        $this->allow($request, 'core.user.manage');
        $data = $this->validated($request, null);

        try {
            $user = $save($request->user(), $data);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('users.edit', $user)->with('status', __('core::ui.users.created', ['email' => $user->email]));
    }

    public function edit(Request $request, User $user): View
    {
        $this->allow($request, 'core.user.view');
        abort_unless($user->user_type === User::TYPE_INTERNAL, 404);

        return view('core::users.form', ['user' => $user, 'roles' => $this->assignableRoles($request->user(), $user), 'mine' => $user->roles()->pluck('name')->all()]);
    }

    public function update(Request $request, User $user, SaveUser $save): RedirectResponse
    {
        $this->allow($request, 'core.user.manage');
        abort_unless($user->user_type === User::TYPE_INTERNAL, 404);
        $data = $this->validated($request, $user);

        try {
            $save($request->user(), $data, $user);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors([($e->details[0]['field'] ?? 'form') => $e->getMessage()]);
        }

        return redirect()->route('users.edit', $user)->with('status', __('core::ui.users.saved'));
    }

    public function sendLink(Request $request, User $user, SendPasswordLink $send): RedirectResponse
    {
        $this->allow($request, 'core.user.manage');
        abort_unless($user->user_type === User::TYPE_INTERNAL, 404);
        $send($user, $request->user());

        return back()->with('status', __('core::ui.users.link_sent', ['email' => $user->email]));
    }

    public function resetMfa(Request $request, User $user, ResetUserMfa $reset): RedirectResponse
    {
        $this->allow($request, 'core.user.manage');
        abort_unless($user->user_type === User::TYPE_INTERNAL, 404);

        try {
            $reset($request->user(), $user);
        } catch (BusinessException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return back()->with('status', __('core::ui.users.mfa_reset'));
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }

    /** @return array{name: string, email: string, locale: ?string, is_active: bool, roles: list<string>} */
    private function validated(Request $request, ?User $user): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)->whereNull('deleted_at')],
            'locale' => ['nullable', Rule::in(config('core.locales'))],
            'roles' => ['sometimes', 'array'], 'roles.*' => ['string', 'max:100'],
        ]);

        return [
            'name' => $data['name'], 'email' => $data['email'], 'locale' => $data['locale'] ?? null,
            'is_active' => $request->boolean('is_active', $user === null),
            'roles' => $data['roles'] ?? [],
        ];
    }

    /**
     * Roles this person may be given or shown: a super-admin sees all; others only roles within their own permissions
     * (plus the roles the person being edited already has, so the page shows them).
     *
     * @return list<string>
     */
    private function assignableRoles(User $actor, ?User $for = null): array
    {
        $isSuper = $actor->hasRole(CoreServiceProvider::SUPER_ADMIN_ROLE);

        return Role::query()->where('guard_name', 'web')->orderBy('name')->get()
            ->filter(fn (Role $r) => $isSuper || ($r->name !== CoreServiceProvider::SUPER_ADMIN_ROLE && $r->permissions->every(fn ($p) => $actor->can($p->name))) || ($for?->hasRole($r->name) ?? false))
            ->pluck('name')->values()->all();
    }
}
