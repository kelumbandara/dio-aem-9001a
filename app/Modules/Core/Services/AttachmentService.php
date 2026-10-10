<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AttachmentScanner;
use App\Modules\Core\Contracts\AttachmentStore;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\DTOs\AttachmentInfo;
use App\Modules\Core\Models\Attachment;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Upload hardening (docs/architecture/security.md §2): the type is decided from the file's content,
 * not from the client; only allow-listed type + extension pairs are stored; files get random names on a
 * private disk; size is capped; an antivirus hook runs before anything is persisted; downloads are signed.
 */
final class AttachmentService implements AttachmentStore
{
    /** Extensions that must never appear anywhere in a file name, e.g. "invoice.php.pdf". */
    private const BLOCKED_SEGMENTS = [
        'php', 'phtml', 'phar', 'exe', 'dll', 'bat', 'cmd', 'com', 'sh', 'js', 'mjs', 'html', 'htm', 'svg',
        'jar', 'msi', 'vbs', 'ps1', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'hta', 'scr',
    ];

    public function __construct(
        private readonly AttachmentScanner $scanner,
        private readonly AuditLogger $audit,
    ) {}

    public function store(UploadedFile $file, Model $attachable, int $uploadedBy): AttachmentInfo
    {
        $path = $file->getRealPath();
        if (! $file->isValid() || $path === false || ! is_file($path)) {
            throw new BusinessException('attachment.upload_failed', 'The file could not be uploaded.', 422);
        }

        $size = (int) filesize($path);
        if ($size === 0) {
            throw new BusinessException('attachment.upload_failed', 'The file is empty.', 422);
        }
        if ($size > (int) config('core.attachments.max_kb') * 1024) {
            throw new BusinessException('attachment.too_large', 'The file is larger than '.config('core.attachments.max_kb').' KB.', 422);
        }

        $name = $this->cleanName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $this->rejectDangerousName($name);

        $mime = $this->detectMime($path, $extension);
        if (! in_array($extension, config('core.attachments.allowed')[$mime] ?? [], true)) {
            throw new BusinessException('attachment.type_not_allowed', 'This file type is not allowed.', 422);
        }

        $scan = $this->scanner->scan($path);
        if ($scan === AttachmentScanner::INFECTED) {
            $this->audit->record('attachment.infected_blocked', $attachable, [], [], ['name' => $name, 'sha256' => hash_file('sha256', $path)]);
            throw new BusinessException('attachment.infected', 'The file was rejected by the virus scan.', 422);
        }

        $diskName = (string) config('core.attachments.disk');
        $stored = now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $written = Storage::disk($diskName)->put($stored, (string) file_get_contents($path), 'private');
        if ($written === false) {
            throw new BusinessException('attachment.upload_failed', 'The file could not be stored.', 500);
        }

        try {
            $attachment = Attachment::query()->create([
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'disk' => $diskName,
                'path' => $stored,
                'original_name' => $name,
                'extension' => $extension,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'sha256' => (string) hash_file('sha256', $path),
                'scan_status' => $scan,
                'scanned_at' => now(),
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (Throwable $e) {
            Storage::disk($diskName)->delete($stored); // no orphan file if the row could not be written
            throw $e;
        }

        $this->audit->record('attachment.uploaded', $attachment, [], [], [
            'attached_to' => $attachable->getMorphClass().'#'.$attachable->getKey(),
            'name' => $name,
            'size_bytes' => $size,
            'sha256' => $attachment->sha256,
            'scan_status' => $scan,
        ]);

        return $this->toInfo($attachment);
    }

    public function list(Model $attachable): array
    {
        return Attachment::query()
            ->where('attachable_type', $attachable->getMorphClass())
            ->where('attachable_id', $attachable->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (Attachment $a) => $this->toInfo($a))
            ->all();
    }

    public function delete(string $attachmentId, int $actorId): void
    {
        $attachment = Attachment::query()->where('public_id', $attachmentId)->firstOrFail();
        $attachment->delete();

        $this->audit->record('attachment.deleted', $attachment, [], [], ['name' => $attachment->original_name, 'by' => $actorId]);
    }

    public function temporaryUrl(string $attachmentId): string
    {
        return URL::temporarySignedRoute(
            'api.v1.attachments.download',
            now()->addMinutes((int) config('core.attachments.download_url_minutes', 5)),
            ['attachment' => $attachmentId],
        );
    }

    public function toInfo(Attachment $a): AttachmentInfo
    {
        return new AttachmentInfo(
            id: $a->public_id,
            originalName: $a->original_name,
            mimeType: $a->mime_type,
            sizeBytes: $a->size_bytes,
            sha256: $a->sha256,
            scanStatus: $a->scan_status,
            uploadedBy: $a->uploaded_by,
            createdAt: CarbonImmutable::instance($a->created_at),
        );
    }

    /** Keep the display name readable (Sinhala/Tamil allowed) but never a path or control characters. */
    private function cleanName(string $raw): string
    {
        $name = basename(str_replace('\\', '/', $raw));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim(mb_substr($name, 0, 200));

        return $name === '' ? 'file' : $name;
    }

    private function rejectDangerousName(string $name): void
    {
        $segments = array_map('strtolower', explode('.', $name));
        array_shift($segments); // the part before the first dot is a name, not an extension

        foreach ($segments as $segment) {
            if (in_array($segment, self::BLOCKED_SEGMENTS, true)) {
                throw new BusinessException('attachment.type_not_allowed', 'This file type is not allowed.', 422);
            }
        }
    }

    /** MIME type from the bytes. libmagic cannot always tell Office files from plain zips, so look inside. */
    private function detectMime(string $path, string $extension): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true)
            && in_array($extension, ['docx', 'xlsx'], true)) {
            $marker = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $zip = new ZipArchive;
            if ($zip->open($path) === true) {
                $found = $zip->locateName($marker) !== false && $zip->locateName('[Content_Types].xml') !== false;
                $zip->close();
                if ($found) {
                    return $extension === 'docx'
                        ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                        : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                }
            }
        }

        if ($extension === 'csv' && in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true)) {
            return 'text/csv';
        }

        return $mime;
    }
}
