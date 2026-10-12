<?php

namespace App\Modules\AI\DTOs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;

/** Who the tool runs for. The AI never has rights of its own: it acts with this person's permissions. */
final readonly class ToolContext
{
    /** @param  \stdClass  $state  counters for this one question (for example how many drafts it has made) */
    public function __construct(public Authorizable $user, public int $userId, public CarbonImmutable $today, public \stdClass $state = new \stdClass) {}
}
