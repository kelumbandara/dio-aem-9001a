<?php

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Core\Contracts\UserDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact in lists, full (steps, decisions, payload, execution) when `$detail` is set.
 *
 * @mixin ApprovalRequest
 */
class ApprovalResource extends JsonResource
{
    public bool $detail = false;

    public function detailed(): static
    {
        $this->detail = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $users = app(UserDirectory::class);

        $data = [
            'id' => $this->public_id,
            'document_no' => $this->document_no,
            'action_key' => $this->action_key,
            'summary' => $this->summary,
            'status' => $this->status,
            'amount' => $this->amount,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $users->names([$this->requested_by])[$this->requested_by] ?? null,
            'channel' => $this->channel,
            'current_step_order' => $this->current_step_order,
            'expires_at' => $this->expires_at->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'version' => $this->version,
        ];

        if (! $this->detail) {
            return $data;
        }

        $names = $users->names(array_filter([
            ...$this->steps->pluck('approver_user_id')->all(),
            ...$this->steps->pluck('decided_by')->all(),
            ...$this->decisions->pluck('decided_by')->all(),
            ...$this->decisions->pluck('on_behalf_of')->all(),
        ]));

        return $data + [
            'payload' => $this->payload,
            'payload_hash' => $this->payload_hash,
            'steps' => $this->steps->map(fn ($s) => [
                'order' => $s->step_order,
                'label' => $s->label,
                'approver_type' => $s->approver_type,
                'approver_role' => $s->approver_role,
                'approver_user' => $s->approver_user_id !== null ? ['id' => $s->approver_user_id, 'name' => $names[$s->approver_user_id] ?? null] : null,
                'status' => $s->status,
                'decided_by' => $s->decided_by !== null ? ['id' => $s->decided_by, 'name' => $names[$s->decided_by] ?? null] : null,
                'decided_at' => $s->decided_at?->toIso8601String(),
            ])->values(),
            'decisions' => $this->decisions->map(fn ($d) => [
                'decision' => $d->decision,
                'decided_by' => ['id' => $d->decided_by, 'name' => $names[$d->decided_by] ?? null],
                'on_behalf_of' => $d->on_behalf_of !== null ? ['id' => $d->on_behalf_of, 'name' => $names[$d->on_behalf_of] ?? null] : null,
                'comment' => $d->comment,
                'channel' => $d->channel,
                'decided_at' => $d->decided_at->toIso8601String(),
            ])->values(),
            'executions' => $this->executions->map(fn ($e) => [
                'attempt' => $e->attempt,
                'status' => $e->status,
                'error_code' => $e->error_code,
                'error_message' => $e->error_message,
                'executed_at' => $e->executed_at->toIso8601String(),
            ])->values(),
        ];
    }
}
