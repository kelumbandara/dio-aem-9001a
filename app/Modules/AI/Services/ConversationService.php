<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\Conversation;
use Illuminate\Database\Eloquent\Collection;

/** Conversations belong to the person who started them; nobody else (not even an administrator) reads another person's chat here. */
final class ConversationService
{
    public function start(int $userId, string $firstMessage, string $agent = AgentRegistry::DEFAULT): Conversation
    {
        $title = trim(mb_substr(preg_replace('/\s+/u', ' ', $firstMessage) ?? '', 0, 80));

        return Conversation::create(['user_id' => $userId, 'title' => $title === '' ? 'Conversation' : $title, 'channel' => 'web', 'agent' => $agent]);
    }

    /** @return Collection<int, Conversation> */
    public function recent(int $userId, int $limit = 30): Collection
    {
        return Conversation::query()->where('user_id', $userId)->whereExists(fn ($q) => $q->selectRaw('1')->from('ai_messages')->whereColumn('ai_messages.conversation_id', 'ai_conversations.id'))->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** The person's own conversation by its public id, or a 404. */
    public function own(string $publicId, int $userId): Conversation
    {
        return Conversation::query()->where('public_id', $publicId)->where('user_id', $userId)->firstOrFail();
    }
}
