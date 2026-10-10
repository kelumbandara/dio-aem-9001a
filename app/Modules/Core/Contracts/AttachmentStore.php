<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\DTOs\AttachmentInfo;
use App\Support\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

interface AttachmentStore
{
    /**
     * Validate and store an upload against a record. The caller has already authorised the user.
     *
     * @param  Model&HasAttachments  $attachable
     *
     * @throws BusinessException attachment.too_large | attachment.type_not_allowed | attachment.infected | attachment.upload_failed
     */
    public function store(UploadedFile $file, Model $attachable, int $uploadedBy): AttachmentInfo;

    /**
     * @param  Model&HasAttachments  $attachable
     * @return list<AttachmentInfo>
     */
    public function list(Model $attachable): array;

    /** Soft-deletes the record; the stored file is purged later by `attachments:purge`. */
    public function delete(string $attachmentId, int $actorId): void;

    /** Time-limited signed API download URL. */
    public function temporaryUrl(string $attachmentId): string;
}
