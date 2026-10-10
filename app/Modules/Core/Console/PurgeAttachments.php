<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeAttachments extends Command
{
    protected $signature = 'attachments:purge {--days=30 : Remove attachments deleted at least this many days ago}';

    protected $description = 'Permanently remove stored files of soft-deleted attachments';

    public function handle(AuditLogger $audit): int
    {
        $purged = 0;

        Attachment::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->each(function (Attachment $attachment) use (&$purged, $audit): void {
                Storage::disk($attachment->disk)->delete($attachment->path);
                $audit->record('attachment.purged', $attachment, [], [], ['name' => $attachment->original_name, 'sha256' => $attachment->sha256]);
                $attachment->forceDelete();
                $purged++;
            });

        $this->info("Purged {$purged} attachment(s).");

        return self::SUCCESS;
    }
}
