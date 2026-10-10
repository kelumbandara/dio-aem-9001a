<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Models\AuditLog;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('core.audit.view'), 403);

        return view('core::audit.index', [
            'entries' => AuditLog::query()->with('user:id,name')->latest('id')->paginate(25),
        ]);
    }
}
