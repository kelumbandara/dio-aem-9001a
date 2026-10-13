<?php

namespace App\Modules\Communications\Contracts;

use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Lets the AI assistant (or any helper acting for a person) prepare a **draft** message to a customer or supplier. A draft is
 * never sent: a person reviews it and presses Send, which applies consent, limits and any approval. The draft shows that the
 * assistant prepared it.
 */
interface MessageDrafter
{
    /**
     * @param  array<string, mixed>  $data  party_type (customer|supplier), party (code), channel (email|whatsapp), template (key), params?, locale?
     * @return array{id: string, channel: string, to: string, template: string, status: string, subject: string|null}
     *
     * @throws BusinessException comm.forbidden when the person may not prepare messages; the usual message errors otherwise
     */
    public function draft(Authorizable $actor, int $userId, array $data): array;
}
