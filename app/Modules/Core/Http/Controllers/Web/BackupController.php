<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Services\DatabaseBackupService;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(Request $request, DatabaseBackupService $backups): View
    {
        $this->authorizeManage($request);

        return view('core::backups.index', ['backups' => $backups->list(), 'keep' => (int) config('core.backups.keep', 8)]);
    }

    public function store(Request $request, DatabaseBackupService $backups): RedirectResponse
    {
        $this->authorizeManage($request);

        try {
            $name = $backups->create($request->user()->id);
        } catch (BusinessException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back()->with('status', __('core::ui.backups.created', ['file' => $name]));
    }

    public function download(Request $request, DatabaseBackupService $backups, AuditLogger $audit, string $name): StreamedResponse
    {
        $this->authorizeManage($request);
        $path = $backups->path($name);   // 404 unless it is exactly one of our copies

        $audit->record('core.backup_downloaded', null, [], [], ['file' => $name]);

        return Storage::disk($backups->disk())->download($path, $name, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('core.backup.manage'), 403);
    }
}
