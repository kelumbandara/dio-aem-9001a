<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $settlement_id
 * @property int $open_item_id
 * @property string $amount
 */
#[Table(name: 'settlement_allocations', timestamps: false)]
#[Fillable(['settlement_id', 'open_item_id', 'amount'])]
class SettlementAllocation extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Allocations are append-only.'));
        static::deleting(fn () => throw new LogicException('Allocations are append-only.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
