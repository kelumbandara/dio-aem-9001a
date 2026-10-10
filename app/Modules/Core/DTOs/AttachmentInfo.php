<?php

namespace App\Modules\Core\DTOs;

use Carbon\CarbonImmutable;

final readonly class AttachmentInfo
{
    public function __construct(
        public string $id,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
        public string $scanStatus,
        public int $uploadedBy,
        public CarbonImmutable $createdAt,
    ) {}
}
