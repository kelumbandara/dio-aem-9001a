<?php

namespace App\Modules\Core\Http\Controllers\Api;

use App\Modules\Core\Services\NotificationPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationPresenter $presenter): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'unread' => ['sometimes', 'in:true,false,1,0'],
        ]);

        $query = filter_var($request->query('unread'), FILTER_VALIDATE_BOOLEAN)
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        $page = $query->latest()->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($n) => $presenter->present($n))->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(null, 204);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(null, 204);
    }
}
