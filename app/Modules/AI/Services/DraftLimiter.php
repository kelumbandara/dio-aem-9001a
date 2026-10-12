<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\DTOs\ToolContext;
use App\Modules\AI\Exceptions\AiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Drafts cost people review time and clutter the lists, so the assistant may make only a few: per question and per hour. */
final class DraftLimiter
{
    /** @throws AiException ai.draft_limit */
    public function assertCanDraft(ToolContext $context): void
    {
        if (($context->state->drafts ?? 0) >= (int) config('ai.max_drafts_per_run')) {
            throw AiException::make('ai.draft_limit', 'Only '.config('ai.max_drafts_per_run').' drafts can be prepared for one question. Tell the person what is left to do.');
        }
        $since = CarbonImmutable::now('UTC')->subHour();
        $recent = (int) DB::selectOne(
            "SELECT COUNT(*) AS n FROM ai_executions e CROSS JOIN LATERAL jsonb_array_elements(e.tool_calls) c
             WHERE e.user_id = ? AND e.created_at > ? AND c->>'risk' = 'R1' AND c->>'status' = 'ok'",
            [$context->userId, $since],
        )->n;
        if ($recent + (int) ($context->state->drafts ?? 0) >= (int) config('ai.drafts_per_hour')) {
            throw AiException::make('ai.draft_limit', 'The limit of '.config('ai.drafts_per_hour').' drafts an hour has been reached. Ask the person to try again later.');
        }
    }

    public function made(ToolContext $context): void
    {
        $context->state->drafts = ($context->state->drafts ?? 0) + 1;
    }
}
