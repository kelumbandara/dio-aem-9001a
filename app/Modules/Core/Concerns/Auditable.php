<?php

namespace App\Modules\Core\Concerns;

use App\Modules\Core\Contracts\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes created/updated/deleted/restored entries to the audit log.
 * Values of sensitive attributes are redacted by the AuditLogger.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        $audit = fn (string $event, Model $model, array $old, array $new) => app(AuditLogger::class)->record($event, $model, $old, $new);

        static::created(fn (Model $m) => $audit('created', $m, [], $m->getAttributes()));

        static::updated(function (Model $m) use ($audit): void {
            $changes = $m->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($m->getRawOriginal(), $changes);
            $audit('updated', $m, $old, $changes);
        });

        static::deleted(fn (Model $m) => $audit('deleted', $m, $m->getAttributes(), []));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $m) => $audit('restored', $m, [], $m->getAttributes()));
        }
    }
}
