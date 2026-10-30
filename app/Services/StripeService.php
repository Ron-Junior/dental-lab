<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\Plan;
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

    public function setClient(?StripeClient $stripe): static
    {
        $this->stripe = $stripe;

        return $this;
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
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
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
     * Create a recurring Stripe subscription for the tenant and plan, with optional discount coupon.
     *
     * @return array{id: string, status: string, latest_invoice_id: ?string}
     */
    public function createSubscription(Tenant $tenant, Plan $plan, ?Discount $discount = null): array
    {
        $customerId = $tenant->stripe_customer_id
            ?? $this->createCustomer($tenant, $tenant->owner_email ?? 'lab@dental.test', $tenant->name);

        if ($this->isMock()) {
            $mockSubId = 'sub_mock_'.bin2hex(random_bytes(8));

            return [
                'id' => $mockSubId,
                'status' => 'active',
                'latest_invoice_id' => 'in_mock_'.bin2hex(random_bytes(6)),
            ];
        }

        try {
            $priceId = $this->getOrCreatePrice($plan);

            $params = [
                'customer' => $customerId,
                'items' => [
                    ['price' => $priceId],
                ],
                'payment_behavior' => 'error_if_incomplete',
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                ],
                'expand' => ['latest_invoice.payment_intent'],
            ];

            if ($discount !== null && ! $discount->is_expired) {
                $couponId = $this->createCouponForDiscount($discount);
                $params['discounts'] = [
                    ['coupon' => $couponId],
                ];
            }

            $subscription = $this->stripe->subscriptions->create($params);

            return [
                'id' => $subscription->id,
                'status' => (string) $subscription->status,
                'latest_invoice_id' => is_string($subscription->latest_invoice)
                    ? $subscription->latest_invoice
                    : $subscription->latest_invoice?->id,
            ];
        } catch (Throwable $e) {
            Log::error('Erro ao criar assinatura no Stripe: '.$e->getMessage(), [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
            ]);

            throw $e;
        }
    }

    /**
     * Create a one-time (duration: once) Stripe coupon corresponding to the given discount.
     */
    public function createCouponForDiscount(Discount $discount): string
    {
        if ($this->isMock()) {
            return 'coup_mock_'.bin2hex(random_bytes(6));
        }

        try {
            $params = [
                'duration' => 'once',
                'name' => 'Desconto Comercial - Dental Lab',
                'metadata' => [
                    'discount_id' => $discount->id,
                    'tenant_id' => $discount->tenant_id,
                ],
            ];

            if ($discount->type === DiscountType::Percentage) {
                $params['percent_off'] = (int) $discount->value_in_cents;
            } else {
                $params['amount_off'] = (int) $discount->value_in_cents;
                $params['currency'] = 'brl';
            }

            $coupon = $this->stripe->coupons->create($params);

            return $coupon->id;
        } catch (Throwable $e) {
            Log::error('Erro ao criar cupom no Stripe: '.$e->getMessage(), [
                'discount_id' => $discount->id,
                'tenant_id' => $discount->tenant_id,
            ]);

            throw $e;
        }
    }

    /**
     * Retrieve the existing stripe_price_id or find/create product and price on Stripe idempotently.
     */
    public function getOrCreatePrice(Plan $plan): string
    {
        if (! empty($plan->stripe_price_id)) {
            return $plan->stripe_price_id;
        }

        if ($this->isMock()) {
            $mockPriceId = 'price_mock_'.bin2hex(random_bytes(6));
            $plan->update(['stripe_price_id' => $mockPriceId]);

            return $mockPriceId;
        }

        try {
            $expectedName = 'Plano '.$plan->name;
            $interval = $plan->billing_cycle === 'annual' ? 'year' : 'month';

            // 1. Verificar se o produto já existe no Stripe para evitar duplicados
            $existingProduct = null;
            $products = $this->stripe->products->all(['active' => true, 'limit' => 50]);
            foreach ($products->data as $prod) {
                if ($prod->name === $expectedName || $prod->name === $plan->name) {
                    $existingProduct = $prod;
                    break;
                }
            }

            if ($existingProduct === null) {
                $existingProduct = $this->stripe->products->create([
                    'name' => $expectedName,
                    'metadata' => [
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                    ],
                ]);
            }

            // 2. Verificar se já existe um preço ativo compatível para esse produto
            $matchingPrice = null;
            $prices = $this->stripe->prices->all([
                'product' => $existingProduct->id,
                'active' => true,
                'limit' => 50,
            ]);

            foreach ($prices->data as $price) {
                if (
                    $price->unit_amount === $plan->price_in_cents
                    && $price->currency === 'brl'
                    && isset($price->recurring)
                    && $price->recurring->interval === $interval
                ) {
                    $matchingPrice = $price;
                    break;
                }
            }

            if ($matchingPrice === null) {
                $matchingPrice = $this->stripe->prices->create([
                    'product' => $existingProduct->id,
                    'unit_amount' => $plan->price_in_cents,
                    'currency' => 'brl',
                    'recurring' => [
                        'interval' => $interval,
                    ],
                    'metadata' => [
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                    ],
                ]);
            }

            $plan->update(['stripe_price_id' => $matchingPrice->id]);

            return $matchingPrice->id;
        } catch (Throwable $e) {
            Log::error('Erro ao sincronizar produto/preço no Stripe: '.$e->getMessage(), [
                'plan_id' => $plan->id,
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
