<?php

namespace App\Models;

use App\Enums\OperationalCostType;
use Database\Factories\OperationalCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable([
    'name',
    'type',
    'amount',
    'is_active',
    'notes',
])]
class OperationalCost extends Model
{
    use CentralConnection;

    /** @use HasFactory<OperationalCostFactory> */
    use HasFactory;

    use HasUuids;

    protected function casts(): array
    {
        return [
            'type' => OperationalCostType::class,
            'amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->type->format((int) $this->amount),
        );
    }
}
