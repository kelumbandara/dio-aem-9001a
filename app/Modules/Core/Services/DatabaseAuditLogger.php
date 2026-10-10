<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

final class DatabaseAuditLogger implements AuditLogger
{
    public function record(
        string $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
    ): void {
        $request = app()->bound('request') ? request() : null;

        AuditLog::query()->create([
            'occurred_at' => now(),
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'old_values' => $old === [] ? null : $this->redact($old),
            'new_values' => $new === [] ? null : $this->redact($new),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip_address' => $this->hasHttpContext() ? $request?->ip() : null,
            'user_agent' => $this->hasHttpContext() ? mb_substr((string) $request?->userAgent(), 0, 255) ?: null : null,
            'channel' => Context::get('channel') ?? (app()->runningInConsole() ? 'console' : 'system'),
            'correlation_id' => Context::get('request_id'),
        ]);
    }

    private function hasHttpContext(): bool
    {
        return Context::has('channel');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        $patterns = config('core.audit.redact', []);

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key, $patterns)) {
                $values[$key] = '***';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    /**
     * A key is sensitive when it equals a pattern or contains it as an underscore-delimited word.
     *
     * @param  list<string>  $patterns
     */
    private function isSensitive(string $key, array $patterns): bool
    {
        $key = strtolower($key);
        $words = explode('_', $key);

        foreach ($patterns as $pattern) {
            if ($key === $pattern || in_array($pattern, $words, true)) {
                return true;
            }
        }

        return false;
    }
}
