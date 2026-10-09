<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'product_id',
    'stock_batch_id',
    'created_by_user_id',
    'type',
    'reason',
    'movable_id',
    'movable_type',
    'quantity',
    'unit_cost',
])]
class StockMovement extends Model
{
    public function movable(): MorphTo
    {
        return $this->morphTo();
    }
}
