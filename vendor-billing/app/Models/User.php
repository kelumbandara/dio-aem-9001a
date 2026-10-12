<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const OWNER = 'owner';

    public const STAFF = 'staff';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    public function isOwner(): bool
    {
        return $this->role === self::OWNER;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'mfa_secret' => 'encrypted', 'mfa_confirmed_at' => 'datetime', 'active' => 'boolean'];
    }
}
