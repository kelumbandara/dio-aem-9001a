<?php

namespace App\Modules\Core\Exceptions;

use App\Support\Exceptions\BusinessException;

final class NumberingException extends BusinessException
{
    public static function unknownSequence(string $key): self
    {
        return new self('core.numbering_unknown_sequence', "Number sequence '{$key}' is not defined or inactive.", 500);
    }
}
