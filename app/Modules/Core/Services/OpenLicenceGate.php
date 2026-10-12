<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\LicenceGate;

/** The gate used when the Licensing module is not installed: no warnings, every module allowed. */
final class OpenLicenceGate implements LicenceGate
{
    public function banner(): ?array
    {
        return null;
    }

    public function allowsModule(string $module): bool
    {
        return true;
    }
}
