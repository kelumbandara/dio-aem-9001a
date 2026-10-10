<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Employee;
use App\Modules\Core\Models\User;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ModuleRegistry $modules): View
    {
        $user = $request->user();

        return view('core::dashboard', [
            'stats' => [
                'users' => User::query()->where('is_active', true)->count(),
                'branches' => Branch::query()->where('is_active', true)->count(),
                'departments' => Department::query()->where('is_active', true)->count(),
                'employees' => Employee::query()->where('status', 'active')->count(),
            ],
            'modules' => $modules->all(),
            'modulesEnabled' => collect($modules->all())->mapWithKeys(fn ($m) => [$m->key => $modules->isEnabled($m->key)]),
            'recentActivity' => $user->can('core.audit.view')
                ? AuditLog::query()->with('user:id,name')->latest('id')->limit(8)->get()
                : null,
        ]);
    }
}
