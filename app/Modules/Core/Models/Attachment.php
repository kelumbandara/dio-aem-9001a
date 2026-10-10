<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Not Auditable via trait: AttachmentService writes explicit upload/download/delete entries.
#[RouteKey('public_id')]
#[Fillable([
    'attachable_type', 'attachable_id', 'disk', 'path', 'original_name', 'extension', 'mime_type',
    'size_bytes', 'sha256', 'scan_status', 'scanned_at', 'uploaded_by',
])]
class Attachment extends Model
{
    use HasPublicId, SoftDeletes;

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'scanned_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
