<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(
    'name',
    'price',
    'billing_cycle',
    'limits',
    'is_active'
)]
class Plan extends Model
{
    
}
