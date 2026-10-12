<?php

namespace App\Modules\Licensing\Http\Controllers\Api;

use App\Modules\Licensing\Services\LicenceService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenceController extends Controller
{
    public function show(LicenceService $licence): JsonResponse
    {
        return response()->json(['data' => $this->payload($licence)]);
    }

    public function store(Request $request, LicenceService $licence): JsonResponse
    {
        abort_unless($request->user()->can('licensing.manage'), 403);
        $data = $request->validate(['licence' => ['required', 'string', 'max:8000']]);
        $licence->install($data['licence'], $request->user()->id);

        return response()->json(['data' => $this->payload($licence)], 201);
    }

    /** @return array<string, mixed> */
    private function payload(LicenceService $licence): array
    {
        return ['installation_id' => $licence->installationId(), 'enforcing' => $licence->enforcing()] + $licence->status()->toArray();
    }
}
