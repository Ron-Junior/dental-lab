<?php

namespace App\Models;

use App\Policies\DentistRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

#[UsePolicy(DentistRequestPolicy::class)]
class DentistRequest extends Model
{
    //
}
