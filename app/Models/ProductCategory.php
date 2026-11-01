<?php

namespace App\Models;

use App\Policies\ProductCategoryPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UsePolicy(ProductCategoryPolicy::class)]
#[Fillable('name')]
class ProductCategory extends Model
{
    use SoftDeletes;
}
