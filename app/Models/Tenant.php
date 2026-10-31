<?php

namespace App\Models;

use App\Enums\Rules;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Policies\TenantPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

#[UsePolicy(TenantPolicy::class)]
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'status',
            'owner_email',
            'verified_at',
            'stripe_customer_id',
            'card_brand',
            'card_last_four',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): ?Subscription
    {
        if ($this->relationLoaded('subscriptions')) {
            return $this->subscriptions
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::Overdue])
                ->sortByDesc('current_period_start')
                ->first()
                ?? $this->subscriptions->sortByDesc('created_at')->first();
        }

        return $this->subscriptions()
            ->with('plan')
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::Overdue])
            ->latest('current_period_start')
            ->first()
            ?? $this->subscriptions()->with('plan')->latest('created_at')->first();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function exemptions(): HasMany
    {
        return $this->hasMany(Exemption::class);
    }

    public function isExempt(?CarbonInterface $at = null): bool
    {
        $now = $at ? Carbon::instance($at) : Carbon::now();

        if ($this->relationLoaded('exemptions')) {
            return $this->exemptions->contains(
                fn (Exemption $exemption): bool => $exemption->isActive($now)
            );
        }

        return $this->exemptions()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->exists();
    }

    public function hasActiveTrial(?CarbonInterface $at = null): bool
    {
        $now = $at ? Carbon::instance($at) : Carbon::now();

        if ($this->relationLoaded('subscriptions')) {
            return $this->subscriptions->contains(
                fn (Subscription $sub): bool => $sub->status === SubscriptionStatus::Trial
                    && $sub->current_period_end !== null
                    && $sub->current_period_end->greaterThan($now)
            );
        }

        return $this->subscriptions()
            ->where('status', SubscriptionStatus::Trial)
            ->where('current_period_end', '>', $now)
            ->exists();
    }

    public function canAccessOperationalFeatures(?CarbonInterface $at = null): bool
    {
        $now = $at ? Carbon::instance($at) : Carbon::now();

        if ($this->status !== TenantStatus::Active) {
            return false;
        }

        if ($this->isExempt($now)) {
            return true;
        }

        $subscription = $this->currentSubscription();

        if (! $subscription) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::Overdue || $subscription->status === SubscriptionStatus::Cancelled) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::Trial) {
            return $subscription->current_period_end !== null
                && $subscription->current_period_end->greaterThan($now);
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            if ($subscription->current_period_end !== null && $subscription->current_period_end->lessThanOrEqualTo($now)) {
                return false;
            }

            if (empty($subscription->stripe_subscription_id)) {
                if ($this->exemptions()->exists() || ! app()->environment('testing')) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    public function operationalDenialReason(?CarbonInterface $at = null): ?string
    {
        $now = $at ? Carbon::instance($at) : Carbon::now();

        if ($this->status !== TenantStatus::Active) {
            return match ($this->status) {
                TenantStatus::PendingPayment => 'pending_payment',
                TenantStatus::Provisioning => 'provisioning',
                default => 'not_active',
            };
        }

        if ($this->isExempt($now)) {
            return null;
        }

        $subscription = $this->currentSubscription();

        if (! $subscription) {
            return 'no_subscription';
        }

        if ($subscription->status === SubscriptionStatus::Overdue) {
            return 'overdue';
        }

        if ($subscription->status === SubscriptionStatus::Cancelled) {
            return 'cancelled';
        }

        if ($subscription->status === SubscriptionStatus::Trial) {
            if ($subscription->current_period_end !== null && $subscription->current_period_end->greaterThan($now)) {
                return null;
            }

            return 'trial_expired';
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            if ($subscription->current_period_end !== null && $subscription->current_period_end->lessThanOrEqualTo($now)) {
                return 'overdue';
            }

            if (empty($subscription->stripe_subscription_id)) {
                if ($this->exemptions()->exists() || ! app()->environment('testing')) {
                    return 'exemption_expired';
                }
            }

            return null;
        }

        return 'not_active';
    }

    public function operationalDenialMessage(?User $user = null, ?CarbonInterface $at = null): ?string
    {
        $reason = $this->operationalDenialReason($at);

        if ($reason === null) {
            return null;
        }

        $user?->loadMissing('rule');
        if ($user && ($user->rule?->name === Rules::Dentist || $user->rule?->name === Rules::LabPartner)) {
            return 'O acesso a este laboratório está temporariamente suspenso. Entre em contato com a administração do laboratório.';
        }

        return match ($reason) {
            'overdue' => 'Acesso suspenso por pendência financeira. Atualize seu cartão de crédito ou regularize sua fatura abaixo para restabelecer o acesso completo ao laboratório.',
            'trial_expired' => 'Seu período de testes terminou. Cadastre um cartão de crédito abaixo para ativar sua assinatura e continuar utilizando o sistema.',
            'exemption_expired' => 'Seu período de isenção foi encerrado. Cadastre uma forma de pagamento para manter sua assinatura ativa.',
            'cancelled' => 'Sua assinatura encontra-se cancelada. Reative seu plano abaixo para voltar a acessar os módulos do laboratório.',
            'pending_payment' => 'Pagamento pendente para ativação do laboratório.',
            'provisioning' => 'Laboratório em processo de provisionamento.',
            default => 'O acesso a este laboratório está temporariamente suspenso por pendência na assinatura.',
        };
    }
}
