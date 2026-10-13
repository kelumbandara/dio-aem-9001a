<?php

namespace App\Modules\Communications\Contracts;

use App\Support\Exceptions\BusinessException;

/**
 * How other modules send something to a customer or supplier (for example "send this purchase order to the supplier"). They never
 * touch channels or consent: this prepares the message from a template and sends it through the same checks as a person would.
 */
interface Messenger
{
    /**
     * @param  array<string, mixed>  $data  party_type, party (code), channel, template, params?, locale?, related_type?, related_id?
     * @return string the message's public id; the message is queued, or waiting for approval when its template is sensitive
     *
     * @throws BusinessException comm.* (no address, no consent, template unavailable, too many messages …)
     */
    public function send(array $data, int $by): string;
}
