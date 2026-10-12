<?php

namespace App\Modules\Licensing\Exceptions;

use App\Support\Exceptions\BusinessException;

final class LicenceException extends BusinessException
{
    public static function make(string $code, string $message, int $status = 422): self
    {
        return new self("licensing.{$code}", $message, $status, $status === 422 ? [['field' => 'licence', 'message' => $message]] : []);
    }
}
