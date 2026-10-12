<?php

namespace App\Modules\Core\Contracts;

/**
 * What the rest of the system may ask about the installation's licence. Core binds a gate that allows everything; the
 * Licensing module replaces it with the real one. Screens call banner() to warn people before and after the licence ends;
 * the module switch calls allowsModule() so a plan that excludes a module really excludes it.
 */
interface LicenceGate
{
    /**
     * A message for the top of every screen, or null when all is well.
     *
     * @return array{level: 'info'|'warning'|'danger', message: string, url: string|null}|null
     */
    public function banner(): ?array;

    /** Whether the licence includes the module (always true when the licence names no modules). */
    public function allowsModule(string $module): bool;
}
