<?php

namespace App\Modules\Communications\Exceptions;

use App\Support\Exceptions\BusinessException;

final class CommunicationsException extends BusinessException
{
    /** @param  string|null  $field  request field the problem belongs to, for form/API error details */
    public static function make(string $code, string $message, int $status = 422, ?string $field = null): self
    {
        return new self($code, $message, $status, $field !== null ? [['field' => $field, 'message' => $message]] : []);
    }

    public static function state(string $what, string $status, string $needs): self
    {
        return self::make('comm.invalid_state', "This {$what} is {$status}; it must be {$needs} for that.", 409);
    }
}
