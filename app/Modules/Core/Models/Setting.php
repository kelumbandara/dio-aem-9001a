<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// Not Auditable via trait: SettingsService writes the audit entry with the resolved scope.
#[Fillable(['key', 'scope', 'scope_id', 'value'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'json', 'scope_id' => 'integer'];
    }
}
