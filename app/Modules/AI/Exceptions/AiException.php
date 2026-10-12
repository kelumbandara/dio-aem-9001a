<?php

namespace App\Modules\AI\Exceptions;

use App\Support\Exceptions\BusinessException;

final class AiException extends BusinessException
{
    /** @param  string|null  $field  request field the problem belongs to, for form/API error details */
    public static function make(string $code, string $message, int $status = 422, ?string $field = null): self
    {
        return new self($code, $message, $status, $field !== null ? [['field' => $field, 'message' => $message]] : []);
    }

    public static function notConfigured(): self
    {
        return self::make('ai.not_configured', 'The AI assistant is not set up yet: ask an administrator to add the provider key.', 503);
    }

    public static function unavailable(string $why = ''): self
    {
        return self::make('ai.provider_unavailable', 'The AI service did not answer. Please try again in a moment.'.($why !== '' ? " ({$why})" : ''), 503);
    }
}
