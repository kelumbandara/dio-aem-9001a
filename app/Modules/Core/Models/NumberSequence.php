<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'format', 'padding', 'reset_policy', 'per_branch', 'is_active'])]
class NumberSequence extends Model
{
    protected function casts(): array
    {
        return ['padding' => 'integer', 'per_branch' => 'boolean', 'is_active' => 'boolean'];
    }
}
