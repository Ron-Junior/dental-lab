<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovements extends Model
{
    public function movable(): MorphTo
    {
        return $this->morphTo();
    }
}
