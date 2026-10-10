<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AttachmentScanner;

final class NullAttachmentScanner implements AttachmentScanner
{
    public function scan(string $absolutePath): string
    {
        return self::SKIPPED;
    }
}
