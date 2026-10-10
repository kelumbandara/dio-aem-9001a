<?php

namespace App\Modules\Core\Contracts;

use Illuminate\Database\Eloquent\Model;

interface AuditLogger
{
    /**
     * Append an audit entry. Actor, IP, channel and correlation id are taken from the
     * current request/context. Sensitive attributes are redacted before storage.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
    ): void;
}
