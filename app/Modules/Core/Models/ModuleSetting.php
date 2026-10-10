<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['module', 'enabled', 'updated_by'])]
class ModuleSetting extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
