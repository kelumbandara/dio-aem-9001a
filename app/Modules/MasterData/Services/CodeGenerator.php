<?php

namespace App\Modules\MasterData\Services;

use App\Modules\Core\Contracts\NumberingService;
use App\Modules\MasterData\Exceptions\MasterDataException;
use Closure;

/**
 * Next free code for a master record. Codes typed in by hand (or imported from another system) may
 * collide with the generated sequence, so generation skips codes that already exist.
 */
final class CodeGenerator
{
    public function __construct(private readonly NumberingService $numbering) {}

    /** @param  Closure(string): bool  $exists */
    public function next(string $sequenceKey, string $format, Closure $exists): string
    {
        $this->numbering->define($sequenceKey, $format, 5);

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $code = $this->numbering->next($sequenceKey);
            if (! $exists($code)) {
                return $code;
            }
        }

        throw MasterDataException::make('masterdata.code_generation_failed', 'Could not generate a free code; check the number sequence.', 500);
    }
}
