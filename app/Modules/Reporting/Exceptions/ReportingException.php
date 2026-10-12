<?php

namespace App\Modules\Reporting\Exceptions;

use App\Support\Exceptions\BusinessException;

final class ReportingException extends BusinessException
{
    /** @param  string|null  $field  request field the problem belongs to, for form/API error details */
    public static function make(string $code, string $message, int $status = 422, ?string $field = null): self
    {
        return new self($code, $message, $status, $field !== null ? [['field' => $field, 'message' => $message]] : []);
    }
}
