<?php

namespace App\Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $bom_version_id
 * @property int $line_no
 * @property int $component_item_id
 * @property string $qty
 * @property int $uom_id
 * @property string $scrap_pct
 * @property string|null $notes
 */
#[Fillable(['bom_version_id', 'line_no', 'component_item_id', 'qty', 'uom_id', 'scrap_pct', 'notes'])]
class BomComponent extends Model
{
    protected $table = 'bom_components';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'qty' => 'decimal:6', 'scrap_pct' => 'decimal:3'];
    }
}
