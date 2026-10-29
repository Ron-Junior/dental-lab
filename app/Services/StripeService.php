<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

class StripeService
{
    protected ?StripeClient $stripe = null;

    public function __construct()
    {
        $secret = config('services.stripe.secret');

        if (! empty($secret) && ! str_contains($secret, 'placeholder')) {
            $this->stripe = new StripeClient($secret);
        }
    }

    /**
     * Create a Stripe customer for the tenant.
     */
    public function createCustomer(Tenant $tenant, string $email, string $name): string
    {
        if ($tenant->stripe_customer_id) {
            return $tenant->stripe_customer_id;
        }

        if ($this->isMock()) {
            $mockCustomerId = 'cus_mock_'.bin2hex(random_bytes(6));
            $tenant->update(['stripe_customer_id' => $mockCustomerId]);

            return $mockCustomerId;
        }

        try {
            $customer = $this->stripe->customers->create([
                'email' => $email,
                'name' => $name,
                'metadata' => [
                    'tenant_id' => $tenant->id,
                ],
            ]);

            $tenant->update(['stripe_customer_id' => $customer->id]);

            return $customer->id;
        } catch (Throwable $e) {
            Log::error('Erro ao criar cliente no Stripe: '.$e->getMessage(), [
                'tenant_id' => $tenant->id,
            ]);

            throw $e;
        }
    }

    /**
     * Create a SetupIntent for collecting card details during Trial.
     *
     * @return array{id: string, client_secret: string}
     */
    public function createSetupIntent(Tenant $tenant): array
    {
        $customerId = $tenant->stripe_customer_id
            ?? $this->createCustomer($tenant, $tenant->owner_email ?? 'lab@dental.test', $tenant->name);

        if ($this->isMock()) {
            return [
                'id' => 'seti_mock_'.bin2hex(random_bytes(6)),
                'client_secret' => 'seti_mock_secret_'.bin2hex(random_bytes(10)),
            ];
        }

        try {
            $setupIntent = $this->stripe->setupIntents->create([
                'customer' => $customerId,
                'payment_method_types' => ['card'],
                'metadata' => [
                    'tenant_id' => $tenant->id,
                ],
            ]);

            return [
                'id' => $setupIntent->id,
                'client_secret' => $setupIntent->client_secret,
            ];
        } catch (Throwable $e) {
            Log::error('Erro ao criar SetupIntent no Stripe: '.$e->getMessage(), [
                'tenant_id' => $tenant->id,
            ]);

            throw $e;
        }
    }

    /**
     * Attach a payment method, set it as default, and save card brand/last4.
     *
     * @return array{brand: string, last4: string}
     */
    public function attachPaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        $customerId = $tenant->stripe_customer_id
            ?? $this->createCustomer($tenant, $tenant->owner_email ?? 'lab@dental.test', $tenant->name);

        if ($this->isMock()) {
            $brand = 'visa';
            $last4 = '4242';

            $tenant->update([
                'card_brand' => $brand,
                'card_last_four' => $last4,
            ]);

            return [
                'brand' => $brand,
                'last4' => $last4,
            ];
        }

        try {
            $paymentMethod = $this->stripe->paymentMethods->retrieve($paymentMethodId);

            if ($paymentMethod->customer !== $customerId) {
                $paymentMethod->attach(['customer' => $customerId]);
            }

            $this->stripe->customers->update($customerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

            $brand = (string) ($paymentMethod->card->brand ?? 'card');
            $last4 = (string) ($paymentMethod->card->last4 ?? '****');

            $tenant->update([
                'card_brand' => $brand,
                'card_last_four' => $last4,
            ]);

            return [
                'brand' => $brand,
                'last4' => $last4,
            ];
        } catch (Throwable $e) {
            Log::error('Erro ao vincular PaymentMethod no Stripe: '.$e->getMessage(), [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            throw $e;
        }
    }

    /**
     * Check if mock mode is active.
     */
    protected function isMock(): bool
    {
        return $this->stripe === null || app()->environment('testing');
    }
}
