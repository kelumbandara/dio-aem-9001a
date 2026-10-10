<?php

namespace App\Modules\Core\Actions\Attachments;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\AttachmentAccess;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shared by the web and API download routes so both apply the same checks and headers. */
final class DownloadAttachment
{
    public function __construct(
        private readonly AttachmentAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Authorizable $user, Attachment $attachment): StreamedResponse
    {
        $this->access->authorizeAttachment($user, $attachment, 'view');

        if ($attachment->scan_status === 'infected') {
            throw new BusinessException('attachment.infected', 'This file is blocked.', 423);
        }

        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->path)) {
            throw new BusinessException('attachment.missing', 'The stored file is missing.', 404);
        }

        $this->audit->record('attachment.downloaded', $attachment);

        // Always a download, never rendered inline; the type is the one detected at upload time.
        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
