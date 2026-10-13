<?php

namespace App\Modules\Communications\Http\Controllers\Concerns;

use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Services\Address;
use Illuminate\Http\Request;

trait PresentsMessages
{
    /**
     * The address is shown hidden in the middle: lists are seen by many and logs travel.
     *
     * @return array<string, mixed>
     */
    protected function present(Message $m, bool $full = false): array
    {
        $row = [
            'id' => $m->public_id, 'direction' => $m->direction, 'channel' => $m->channel, 'status' => $m->status, 'to' => Address::mask($m->channel, $m->address),
            'party_type' => $m->party_type, 'template' => $m->template_key, 'subject' => $m->subject, 'drafted_by_ai' => $m->drafted_by_ai,
            'created_at' => $m->created_at->toIso8601String(), 'sent_at' => $m->sent_at?->toIso8601String(), 'delivered_at' => $m->delivered_at?->toIso8601String(), 'read_at' => $m->read_at?->toIso8601String(),
            'error' => $m->error, 'error_code' => $m->error_code,
        ];

        return $full ? $row + ['body' => $m->body, 'attempts' => $m->attempts, 'approval_request_id' => $m->approval_request_id] : $row;
    }

    protected function needs(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}
