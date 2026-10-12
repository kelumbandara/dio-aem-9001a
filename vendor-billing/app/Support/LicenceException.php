<?php

namespace App\Support;

use RuntimeException;

final class LicenceException extends RuntimeException
{
    public static function make(string $code, string $message, int $status = 422): self
    {
        return new self($message, $status);
    }
}
