<?php

namespace App\Modules\Communications\Http\Controllers\Api;

use App\Modules\Communications\Models\Identity;
use App\Modules\Communications\Services\Address;
use App\Modules\Communications\Services\IdentityService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The signed-in person's own phone numbers and e-mail addresses (each proved by a code). */
class ChannelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Identity::query()->where('user_id', $request->user()->id)->orderBy('id')->get()->map(fn (Identity $i) => $this->row($i))->values()]);
    }

    public function start(Request $request, IdentityService $identities): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp'])], 'address' => ['required', 'string', 'max:190']]);
        $identities->start($request->user()->id, $data['channel'], $data['address']);

        return response()->json(['data' => ['sent' => true, 'minutes' => (int) config('communications.otp.ttl_minutes')]], 202);
    }

    public function confirm(Request $request, IdentityService $identities): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'whatsapp'])], 'code' => ['required', 'string', 'max:12']]);

        return response()->json(['data' => $this->row($identities->confirm($request->user()->id, $data['channel'], $data['code']))], 201);
    }

    public function unlink(Request $request, string $identity, IdentityService $identities): JsonResponse
    {
        $identities->unlink($request->user()->id, $identity);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function row(Identity $i): array
    {
        return ['id' => $i->public_id, 'channel' => $i->channel, 'address' => Address::mask($i->channel, $i->address), 'verified_at' => $i->verified_at->toIso8601String()];
    }
}
