<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * A business-rule failure with a stable machine code (docs/architecture/api-standards.md §3).
 * Rendered as the standard API error envelope by the API exception renderer.
 */
class BusinessException extends RuntimeException
{
    /**
     * @param  list<array{field?: string, message: string}>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
