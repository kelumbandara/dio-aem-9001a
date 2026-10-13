<?php

namespace App\Modules\Communications\Services\Channels;

use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Models\Template;

/** One way of delivering a message (e-mail, WhatsApp). The rules (consent, approval, limits) are applied before a driver is ever called. */
interface ChannelDriver
{
    /** False when the channel's settings are missing; sending then fails with a clear reason instead of going nowhere. */
    public function configured(): bool;

    /**
     * @return string the provider's id for the message (used to match delivery reports)
     *
     * @throws ChannelException
     */
    public function send(Message $message, ?Template $template): string;
}
