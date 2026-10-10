<?php

namespace App\Modules\Approvals\Http\Controllers\Api;

use App\Modules\Approvals\Actions\CreateDelegation;
use App\Modules\Approvals\Http\Requests\StoreDelegationRequest;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Support\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DelegationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = ApprovalDelegation::query()
            ->where(fn ($q) => $q->where('delegator_id', $request->user()->id)->orWhere('delegate_id', $request->user()->id))
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($d) => $this->payload($d))->values()]);
    }

    public function store(StoreDelegationRequest $request, CreateDelegation $create): JsonResponse
    {
        $delegation = $create(
            $request->user()->id,
            (int) $request->validated('delegate_id'),
            CarbonImmutable::parse($request->validated('starts_at')),
            CarbonImmutable::parse($request->validated('ends_at')),
            $request->validated('action_key'),
            $request->validated('reason'),
        );

        return response()->json(['data' => $this->payload($delegation)], 201);
    }

    /** Only the delegator can revoke their own delegation. */
    public function destroy(Request $request, ApprovalDelegation $delegation): JsonResponse
    {
        abort_unless($delegation->delegator_id === $request->user()->id, 403);
        $delegation->forceFill(['revoked_at' => now()])->save();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(ApprovalDelegation $d): array
    {
        return [
            'id' => $d->id,
            'delegator_id' => $d->delegator_id,
            'delegate_id' => $d->delegate_id,
            'action_key' => $d->action_key,
            'starts_at' => $d->starts_at->toIso8601String(),
            'ends_at' => $d->ends_at->toIso8601String(),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'active' => $d->revoked_at === null && $d->starts_at->lte(now()) && $d->ends_at->gt(now()),
        ];
    }
}
