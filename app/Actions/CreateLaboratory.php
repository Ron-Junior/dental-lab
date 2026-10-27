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
     * Create a laboratory (tenant), associated subscription/payments, and dispatch provisioning.
     *
     * @param  array{
     *     slug: string,
     *     laboratory_name: string,
     *     admin_name: string,
     *     email: string,
     *     password: string,
     *     plan_id?: string|null,
     * }  $data
     * @param  array{
     *     starts_at: CarbonInterface,
     *     ends_at: CarbonInterface,
     *     reason: string,
     * }|null  $exemptionData  Only permitted when $creator is an authenticated CentralUser.
     *
     * @throws InvalidArgumentException
     */
    public function handle(
        array $data,
        ?array $exemptionData = null,
        ?CentralUser $creator = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Tenant {
        // Exemption can strictly only be granted by an authenticated central user
        if ($exemptionData !== null && $creator === null) {
            throw new InvalidArgumentException('Isenção de pagamento só pode ser concedida por administradores centrais.');
        }

        $centralConnection = config('tenancy.database.central_connection');
        $plan = ! empty($data['plan_id'])
            ? Plan::query()->findOrFail($data['plan_id'])
            : Plan::query()->where('is_active', true)->first();

        $userData = [
            'name' => $data['admin_name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ];

        $tenant = DB::connection($centralConnection)->transaction(function () use (
            $data,
            $plan,
            $exemptionData,
            $creator,
            $ipAddress,
            $userAgent
        ): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->create([
                'id' => $data['slug'],
                'name' => $data['laboratory_name'],
                'status' => TenantStatus::Provisioning,
            ]);

            $tenant->domains()->create([
                'domain' => $data['slug'].'.'.config('tenancy.central_domain'),
            ]);

            $now = Carbon::now();

            if ($plan !== null) {
                if ($exemptionData !== null && $creator !== null) {
                    $startsAt = Carbon::instance($exemptionData['starts_at']);
                    $endsAt = Carbon::instance($exemptionData['ends_at']);

                    // Create active subscription covering the promotional exemption period
                    Subscription::query()->create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
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
                } else {
                    // Regular registration / no exemption: subscription with pending payment
                    $subEnd = $plan->billing_cycle === 'annual'
                        ? $now->copy()->addYear()
                        : $now->copy()->addMonth();

                    Subscription::query()->create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                        'status' => SubscriptionStatus::Active,
                        'current_period_start' => $now,
                        'current_period_end' => $subEnd,
                    ]);

                    $tenant->payments()->create([
                        'status' => PaymentStatus::Pending,
                        'amount_in_cents' => $plan->price_in_cents,
                        'due_date' => $now->copy()->addDays(3),
                    ]);
                }
            }

            return $tenant;
        });

        Bus::chain([
            new CreateDatabase($tenant),
            new ProvisionTenant($tenant->id, $userData),
        ])->dispatch();

        return $tenant;
    }
}
