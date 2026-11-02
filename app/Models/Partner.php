<?php

namespace App\Models;

use App\Policies\PartnerPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UsePolicy(PartnerPolicy::class)]
#[Fillable(['user_id', 'phone', 'is_active', 'started_date'])]
class Partner extends Model
{
    use HasFactory, SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function serviceSteps(): BelongsToMany
    {
        return $this->belongsToMany(ServiceStep::class, 'service_step_partner', 'partner_id', 'service_step_id');
    }

    public function scopeSearch($query, string $value)
    {
        return $query->whereRelation('user', 'name', 'like', "%{$value}%")
            ->orWhereRelation('user', 'email', 'like', "%{$value}%");
    }
}
