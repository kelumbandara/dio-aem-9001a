<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModuleController extends Controller
{
    public function index(Request $request, ModuleRegistry $modules): View
    {
        $this->authorizeManage($request);

        return view('core::modules.index', [
            'modules' => $modules->all(),
            'enabled' => collect($modules->all())->mapWithKeys(fn ($m) => [$m->key => $modules->isEnabled($m->key)]),
        ]);
    }

    public function enable(Request $request, ModuleRegistry $modules, string $module): RedirectResponse
    {
        return $this->toggle($request, fn () => $modules->enable($module, $request->user()->id), __('core::ui.modules.enabled', ['module' => $module]));
    }

    public function disable(Request $request, ModuleRegistry $modules, string $module): RedirectResponse
    {
        return $this->toggle($request, fn () => $modules->disable($module, $request->user()->id), __('core::ui.modules.disabled', ['module' => $module]));
    }

    private function toggle(Request $request, callable $change, string $success): RedirectResponse
    {
        $this->authorizeManage($request);

        try {
            $change();
        } catch (BusinessException $e) {
            return back()->withErrors(['module' => $e->getMessage()]);
        }

        return back()->with('status', $success);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('core.module.manage'), 403);
    }
}
