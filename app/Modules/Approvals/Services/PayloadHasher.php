<?php

namespace App\Modules\Approvals\Services;

use InvalidArgumentException;

/**
 * Canonical SHA-256 of a payload: associative keys sorted recursively, lists kept in order, unicode and
 * slashes unescaped. The same data always yields the same hash, also after a JSON round-trip through the
 * database. Floats are refused: money and quantities travel as decimal strings.
 */
final class PayloadHasher
{
    /** @param  array<array-key, mixed>  $payload */
    public function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonical($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Approval payloads must not contain floats; use decimal strings.');
        }
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($v) => $this->canonical($v), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value === [] ? [] : $value;
    }
}
