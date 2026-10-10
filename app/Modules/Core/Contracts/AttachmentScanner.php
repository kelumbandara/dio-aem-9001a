<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Services\NullAttachmentScanner;

/**
 * Antivirus hook. Bind a real implementation (e.g. ClamAV over a socket) in a service provider;
 * until then {@see NullAttachmentScanner} records the scan as "skipped".
 */
interface AttachmentScanner
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const SKIPPED = 'skipped';

    /** @return 'clean'|'infected'|'skipped' */
    public function scan(string $absolutePath): string;
}
