<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Services\NotificationPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationPresenter $presenter): View
    {
        $page = $request->user()->notifications()->latest()->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($n) => $presenter->present($n)));

        return view('core::notifications.index', ['notifications' => $page]);
    }

    /** Marks one notification read, then follows its link (only to a path on this site). */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        $url = $item->data['url'] ?? null;

        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? redirect($url)
            : redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
