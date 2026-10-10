<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\HasAttachments;
use App\Modules\Core\Models\Attachment;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves what an attachment is attached to and applies that record's own policy: an attachment is
 * exactly as visible as its parent.
 */
final class AttachmentAccess
{
    /** Find the target record from an API alias + public id. Only models implementing HasAttachments qualify. */
    public function resolveTarget(string $alias, string $publicId): Model&HasAttachments
    {
        $class = Relation::getMorphedModel($alias);

        if ($class === null || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, HasAttachments::class)) {
            throw new BusinessException('attachment.unknown_target', 'Attachments cannot be added to this kind of record.', 422);
        }

        $record = $class::query()->where('public_id', $publicId)->first();
        if (! $record instanceof HasAttachments) {
            throw new NotFoundHttpException('Record not found.');
        }

        return $record;
    }

    /** @param  'view'|'attach'  $ability */
    public function authorizeTarget(Authorizable $user, Model $target, string $ability): void
    {
        if (! Gate::forUser($user)->allows($ability, $target)) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }
    }

    /** @param  'view'|'attach'  $ability */
    public function authorizeAttachment(Authorizable $user, Attachment $attachment, string $ability): Model
    {
        $target = $attachment->attachable;
        if (! $target instanceof Model || ! $target instanceof HasAttachments) {
            throw new NotFoundHttpException('Record not found.');
        }

        $this->authorizeTarget($user, $target, $ability);

        return $target;
    }
}
