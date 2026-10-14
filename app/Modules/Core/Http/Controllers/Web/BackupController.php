<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Services\DatabaseBackupService;
use App\Modules\Core\Services\DatabaseRestoreService;
use App\Modules\Core\Services\MfaService;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public const CONFIRM_WORD = 'RESTORE';

    public function index(Request $request, DatabaseBackupService $backups): View
    {
        $this->authorizeManage($request);

        return view('core::backups.index', [
            'backups' => $backups->list(),
            'keep' => (int) config('core.backups.keep', 8),
            'canRestore' => $request->user()->can('core.backup.restore'),
            'confirmWord' => self::CONFIRM_WORD,
        ]);
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

    /** Puts a saved copy back as the live database. Needs the typed word, the person's own password and a fresh authenticator code. */
    public function restore(Request $request, DatabaseBackupService $backups, DatabaseRestoreService $restore, MfaService $mfa, string $name): RedirectResponse
    {
        abort_unless($request->user()->can('core.backup.restore'), 403);
        $backups->path($name);   // 404 unless it is one of our copies

        $data = $request->validate(['confirm' => ['required', 'string'], 'password' => ['required', 'string'], 'code' => ['required', 'string']]);
        $user = $request->user();
        if (trim($data['confirm']) !== self::CONFIRM_WORD) {
            return back()->withErrors(['confirm' => __('core::ui.backups.bad_confirm', ['word' => self::CONFIRM_WORD])]);
        }
        if (! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => __('core::ui.backups.bad_password')]);
        }
        if (! $mfa->verify($user, $data['code'])) {
            return back()->withErrors(['code' => __('core::ui.backups.bad_code')]);
        }

        try {
            $result = $restore->restore($name, $user->id, $user->name);
        } catch (BusinessException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        // The sessions table came back with the database: nobody, including this person, is signed in any more.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('core::ui.backups.restored', ['file' => $result['restored'], 'safety' => $result['safety_copy']]));
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('core.backup.manage'), 403);
    }
}
