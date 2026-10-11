<?php

namespace App\Modules\MasterData\Exceptions;

use App\Support\Exceptions\BusinessException;

final class MasterDataException extends BusinessException
{
    /** @param  string|null  $field  request field the problem belongs to, for form/API error details */
    public static function make(string $code, string $message, int $status = 422, ?string $field = null): self
    {
        return new self($code, $message, $status, $field !== null ? [['field' => $field, 'message' => $message]] : []);
    }

    public static function notFound(string $what): self
    {
        return self::make("masterdata.{$what}_not_found", ucfirst(str_replace('_', ' ', $what)).' not found.', 404);
    }

    public static function inactive(string $what): self
    {
        return self::make("masterdata.{$what}_inactive", ucfirst(str_replace('_', ' ', $what)).' is inactive.', 422);
    }

    public static function stale(string $what): self
    {
        return new self(
            'concurrency.stale_version',
            "This {$what} was changed by someone else. Reload and try again.",
            409,
            [['field' => 'version', 'message' => 'Stale version.']],
        );
    }
}
