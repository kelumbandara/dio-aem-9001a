<?php

namespace App\Modules\Finance\DTOs;

final readonly class PostedJournal
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $journalNo,
        public string $total,       // sum of the debits (= sum of the credits)
        public bool $replayed,      // true when the source had already been posted and nothing new happened
    ) {}
}
