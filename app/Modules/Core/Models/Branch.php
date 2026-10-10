<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use App\Modules\Core\Database\Factories\BranchFactory;
use App\Modules\Core\Policies\BranchPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(BranchFactory::class)]
#[UsePolicy(BranchPolicy::class)]
#[RouteKey('public_id')]
#[Fillable(['code', 'name', 'is_head_office', 'address_line1', 'address_line2', 'city', 'phone', 'email', 'is_active'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use Auditable, HasFactory, HasPublicId, SoftDeletes;

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean', 'is_active' => 'boolean'];
    }
}
