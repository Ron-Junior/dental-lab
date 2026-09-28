<?php

namespace App\Models;

use App\Enums\DemandStatus;
use App\Policies\PartnerDemandPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(PartnerDemandPolicy::class)]
#[Fillable(['partner_id', 'request_service_id', 'service_service_step_id', 'order', 'partner_commission', 'partner_commission_type', 'started_at', 'ended_at'])]
class PartnerDemand extends Model
{
    use HasFactory;

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function requestService(): BelongsTo
    {
        return $this->belongsTo(RequestService::class);
    }

    public function serviceServiceStep(): BelongsTo
    {
        return $this->belongsTo(ServiceServiceStep::class);
    }

    #[Scope]
    protected function search(Builder $query, string $search): void
    {
        $query->when($search, function ($query) use ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('requestService.service', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('serviceServiceStep.serviceStep', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('requestService.dentistRequest.dentist.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('partner.user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        });
    }

    #[Scope]
    protected function assignedToPartner(Builder $query, array $userIds): void
    {
        $query->when(count($userIds), function ($query) use ($userIds) {
            $query->whereIn('partner_id', $userIds);
        });
    }

    #[Scope]
    protected function withStatus(Builder $query, ?string $status): void
    {
        $query->when($status === 'pending', fn ($q) => $q->whereNull('partner_id')->whereNull('started_at')->whereNull('ended_at'))
            ->when($status === 'assigned', fn ($q) => $q->whereNotNull('partner_id')->whereNull('started_at')->whereNull('ended_at'))
            ->when($status === 'in_progress', fn ($q) => $q->whereNotNull('partner_id')->whereNotNull('started_at')->whereNull('ended_at'))
            ->when($status === 'done', fn ($q) => $q->whereNotNull('partner_id')->whereNotNull('started_at')->whereNotNull('ended_at'));
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->ended_at) {
                    return DemandStatus::Done;
                }
                if ($this->started_at) {
                    return 'in_progress';
                }
                if ($this->partner_id) {
                    return 'assigned';
                }
                return 'pending';
            }
        );
    }

    protected function canStartNow(): Attribute
    {
        $this->loadMissing('requestService.partnerDemands');
        
        $previousDemands = $this->requestService->partnerDemands->where('order', '<', $this->order);

        return Attribute::make(
            get: fn () => $this->status === 'assigned' && $previousDemands->every(fn ($demand) => $demand->status === 'done'),
        );
    }

    protected function canCompleteNow(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'in_progress',
        );
    }
}
