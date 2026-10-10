<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $secret base32 TOTP secret (encrypted at rest)
 * @property list<string>|null $recovery_codes password hashes of unused recovery codes (encrypted at rest)
 */
// Not Auditable: the row holds secrets. MFA events are audited explicitly by MfaService.
#[Fillable(['user_id', 'secret', 'recovery_codes', 'confirmed_at', 'last_used_timestep'])]
#[Hidden(['secret', 'recovery_codes'])]
class MfaSecret extends Model
{
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
