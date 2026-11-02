<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['request_service_id', 'product_id', 'stock_batch_id', 'quantity_used'])]
class RequestServiceConsumption extends Model
{
    use HasFactory;

    protected $casts = [
        'quantity_used' => 'decimal:2',
    ];

    public function requestService(): BelongsTo
    {
        return $this->belongsTo(RequestService::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }
}
