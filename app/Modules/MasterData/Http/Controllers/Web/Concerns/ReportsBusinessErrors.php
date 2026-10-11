<?php

namespace App\Modules\MasterData\Http\Controllers\Web\Concerns;

use App\Support\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\RedirectResponse;

trait ReportsBusinessErrors
{
    /**
     * Runs a save; a business-rule failure goes back to the form with the message on the offending field
     * (or at the top when no field is named), keeping what the person typed.
     *
     * @param  Closure(): RedirectResponse  $action
     */
    protected function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (BusinessException $e) {
            $field = $e->details[0]['field'] ?? 'form';

            return back()->withInput()->withErrors([str_starts_with($field, 'changes.') || $field === 'version' ? 'form' : $field => $e->getMessage()]);
        }
    }
}
