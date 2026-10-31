<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\CentralUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\ExemptionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Stancl\Tenancy\Jobs\CreateDatabase;

class CreateLaboratory
{
    public function __construct(
        protected ExemptionService $exemptionService,
    ) {}

    /**
     * Create a laboratory (tenant) and its associated subscription/exemption.
     *
     * @param  array{
     *     slug: string,
     *     laboratory_name: string,
     *     admin_name: string,
     *     email: string,
     *     plan_id?: string|null,
     * }  $data
     * @param  array{
     *     starts_at: CarbonInterface,
     *     ends_at: CarbonInterface,
     *     reason: string,
     * }|null  $exemptionData  Only permitted when $creator is an authenticated CentralUser.
     * @param  int|null  $trialDays  Only permitted when $creator is an authenticated CentralUser.
     *
     * @throws InvalidArgumentException
     */
    public function handle(
        array $data,
        ?array $exemptionData = null,
        ?int $trialDays = null,
        ?CentralUser $creator = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Tenant {
        // Exemption and trial periods can strictly only be granted by an authenticated central user (partner/admin)
        if ($exemptionData !== null && $creator === null) {
            throw new InvalidArgumentException('Isenção de pagamento só pode ser concedida por administradores centrais.');
        }

        if ($trialDays !== null && $trialDays > 0 && $creator === null) {
            throw new InvalidArgumentException('Período de teste só pode ser concedido por administradores ou sócios.');
        }

        $centralConnection = config('tenancy.database.central_connection');
        $plan = ! empty($data['plan_id'])
            ? Plan::query()->findOrFail($data['plan_id'])
            : Plan::query()->where('is_active', true)->first();

        $isExempt = ($exemptionData !== null && $creator !== null);
        $hasTrial = ($trialDays !== null && $trialDays > 0 && $creator !== null);
        $bypassCheckout = ($isExempt || $hasTrial);

        $tenant = DB::connection($centralConnection)->transaction(function () use (
            $data,
            $plan,
            $exemptionData,
            $trialDays,
            $creator,
            $ipAddress,
            $userAgent,
            $isExempt,
            $hasTrial,
            $bypassCheckout
        ): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->create([
                'id' => $data['slug'],
                'name' => $data['laboratory_name'],
                'owner_email' => $data['email'],
                'verified_at' => null,
                'status' => $bypassCheckout ? TenantStatus::Provisioning : TenantStatus::PendingPayment,
            ]);

            $tenant->domains()->create([
                'domain' => $data['slug'].'.'.config('tenancy.central_domain'),
            ]);

            $now = Carbon::now();

            if ($plan !== null) {
                if ($isExempt) {
                    $startsAt = Carbon::instance($exemptionData['starts_at']);
                    $endsAt = Carbon::instance($exemptionData['ends_at']);

                    // Create active subscription covering the promotional exemption period
                    Subscription::query()->create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                        'price_in_cents' => $plan->price_in_cents,
                        'stripe_price_id' => $plan->stripe_price_id,
                        'status' => SubscriptionStatus::Active,
                        'current_period_start' => $startsAt,
                        'current_period_end' => $endsAt,
                    ]);

                    // Grant audited exemption
                    $this->exemptionService->grant(
                        $tenant,
                        $startsAt,
                        $endsAt,
                        $exemptionData['reason'],
                        $creator,
                        $ipAddress,
                        $userAgent
                    );
                } elseif ($hasTrial) {
                    // Trial period specifically granted by a partner
                    $subEnd = $now->copy()->addDays($trialDays);

                    Subscription::query()->create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                        'price_in_cents' => $plan->price_in_cents,
                        'stripe_price_id' => $plan->stripe_price_id,
                        'status' => SubscriptionStatus::Trial,
                        'current_period_start' => $now,
                        'current_period_end' => $subEnd,
                    ]);

                    $tenant->payments()->create([
                        'status' => PaymentStatus::Pending,
                        'amount_in_cents' => $plan->price_in_cents,
                        'due_date' => $subEnd,
                    ]);
                } else {
                    // Regular registration: immediate active subscription without trial
                    $subEnd = $plan->billing_cycle === 'annual' ? $now->copy()->addYear() : $now->copy()->addMonth();

                    Subscription::query()->create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                        'price_in_cents' => $plan->price_in_cents,
                        'stripe_price_id' => $plan->stripe_price_id,
                        'status' => SubscriptionStatus::Active,
                        'current_period_start' => $now,
                        'current_period_end' => $subEnd,
                    ]);

                    $tenant->payments()->create([
                        'status' => PaymentStatus::Pending,
                        'amount_in_cents' => $plan->price_in_cents,
                        'due_date' => $now,
                    ]);
                }
            }

            return $tenant;
        });

        // If exempt or granted trial by partner, bypass checkout and start provisioning immediately
        if ($bypassCheckout) {
            static::dispatchProvisioning($tenant, [
                'name' => $data['admin_name'],
                'email' => $data['email'],
            ]);
        }

        return $tenant;
    }

    /**
     * Dispatch provisioning database and tenant initial setup jobs.
     *
     * @param  array{name?: string, email?: string}  $userData
     */
    public static function dispatchProvisioning(Tenant $tenant, array $userData = []): void
    {
        $tenant->status = TenantStatus::Provisioning;
        $tenant->save();

        if (empty($userData['email'])) {
            $userData['email'] = $tenant->owner_email ?? '';
        }

        if (empty($userData['name'])) {
            $userData['name'] = $tenant->name;
        }

        Bus::chain([
            new CreateDatabase($tenant),
            new ProvisionTenant($tenant->id, $userData),
        ])->dispatch();
    }
}
