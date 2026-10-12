<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** An installation fetches its newest licence here, with the bearer token issued for it. */
class LicenceController extends Controller
{
    public function show(Request $request, string $installation): JsonResponse
    {
        $bearer = (string) $request->bearerToken();
        $customer = Customer::query()->where('installation_id', $installation)->first();

        // Same answer for "no such installation" and "wrong token", so installation IDs cannot be probed.
        if ($customer === null || $bearer === '' || $customer->api_token_hash === null || ! hash_equals($customer->api_token_hash, hash('sha256', $bearer))) {
            return response()->json(['error' => ['code' => 'unauthorized', 'message' => 'Unknown installation or token.']], 401);
        }

        $licence = $customer->licences()->orderByDesc('id')->first();
        if ($licence === null) {
            return response()->json(['error' => ['code' => 'no_licence', 'message' => 'No licence has been issued yet.']], 404);
        }

        return response()->json(['data' => [
            'serial' => $licence->serial, 'kind' => $licence->kind, 'valid_from' => $licence->valid_from->toDateString(),
            'valid_until' => $licence->valid_until->toDateString(), 'licence' => $licence->token,
        ]]);
    }
}
