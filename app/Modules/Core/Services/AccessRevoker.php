<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a person's existing sign-ins: every API token, and (when sessions are kept in the database) every browser session
 * except, optionally, the one making the request. Used when an account is switched off, a password changes or MFA is reset.
 */
final class AccessRevoker
{
    public function revoke(User $user, ?string $keepSessionId = null): void
    {
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }
}
