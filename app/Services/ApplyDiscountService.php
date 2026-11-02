<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CentralUser;
use App\Models\Discount;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApplyDiscountService
{
    /**
     * Apply a discount to the tenant's next pending payment.
     *
     * @throws InvalidArgumentException
     */
    public function apply(Discount $discount): Payment
    {
        $tenant = $discount->tenant;

        if ($tenant === null) {
            throw new InvalidArgumentException('Desconto não possui tenant associado.');
        }

        if ($tenant->isExempt()) {
            throw new InvalidArgumentException('Tenant possui isenção ativa de pagamento.');
        }

        if ($discount->is_expired) {
            throw new InvalidArgumentException('Desconto expirado.');
        }

        if ($discount->is_applied) {
            throw new InvalidArgumentException('Desconto já aplicado.');
        }

        $centralConnection = config('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use ($discount, $tenant): Payment {
            // Lock the discount row for update
            /** @var Discount $freshDiscount */
            $freshDiscount = Discount::query()
                ->lockForUpdate()
                ->findOrFail($discount->id);

            if ($freshDiscount->is_expired) {
                throw new InvalidArgumentException('Desconto expirado.');
            }

            if ($freshDiscount->is_applied) {
                throw new InvalidArgumentException('Desconto já aplicado.');
            }

            // Find next pending payment (ordered by due_date ASC, then created_at ASC)
            /** @var Payment|null $nextPayment */
            $nextPayment = Payment::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', PaymentStatus::Pending)
                ->orderBy('due_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if ($nextPayment === null) {
                throw new InvalidArgumentException('Nenhum pagamento pendente encontrado para aplicar o desconto.');
            }

            $currentMetadata = $nextPayment->metadata ?? [];

            if (isset($currentMetadata['discount'])) {
                throw new InvalidArgumentException('O próximo pagamento pendente já possui um desconto aplicado.');
            }

            $originalAmount = (int) $nextPayment->amount_in_cents;

            if ($freshDiscount->type === DiscountType::Percentage) {
                $percentage = (int) $freshDiscount->value_in_cents;
                $discountInCents = (int) floor(($originalAmount * $percentage) / 100);
            } else {
                $discountInCents = (int) $freshDiscount->value_in_cents;
            }

            $discountInCents = max(0, $discountInCents);
            $newAmount = max(0, $originalAmount - $discountInCents);

            $currentMetadata['discount'] = [
                'discount_id' => $freshDiscount->id,
                'original_amount_in_cents' => $originalAmount,
                'discount_in_cents' => $discountInCents,
            ];

            $nextPayment->amount_in_cents = $newAmount;
            $nextPayment->metadata = $currentMetadata;
            $nextPayment->save();

            $freshDiscount->applied_payment_id = $nextPayment->id;
            $freshDiscount->applied_at = Carbon::now();
            $freshDiscount->save();

            return $nextPayment;
        });
    }

    /**
     * Recalculate a pending payment's amount and discount metadata for a new base price.
     *
     * @throws InvalidArgumentException
     */
    public function recalculateForPayment(
        Payment $payment,
        int $baseAmountInCents,
        ?Discount $discount = null,
    ): Payment {
        $baseAmountInCents = max(0, $baseAmountInCents);

        $centralConnection = config('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use (
            $payment,
            $baseAmountInCents,
            $discount,
        ): Payment {
            /** @var Payment $freshPayment */
            $freshPayment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($freshPayment->status !== PaymentStatus::Pending) {
                throw new InvalidArgumentException('Não é possível recalcular o valor de uma fatura que não está pendente.');
            }

            // Resolução do desconto aplicável
            if ($discount === null) {
                $discountId = $freshPayment->metadata['discount']['discount_id'] ?? null;
                if ($discountId !== null) {
                    $discount = Discount::query()->lockForUpdate()->find($discountId);
                }

                if ($discount === null && $freshPayment->tenant_id) {
                    $discount = Discount::query()
                        ->where('tenant_id', $freshPayment->tenant_id)
                        ->unapplied()
                        ->notExpired()
                        ->latest()
                        ->lockForUpdate()
                        ->first();
                }
            } else {
                $discount = Discount::query()->lockForUpdate()->find($discount->id) ?? $discount;
            }

            $currentMetadata = $freshPayment->metadata ?? [];

            // Caso 1 — Desconto válido e não expirado
            if ($discount !== null && ! $discount->is_expired) {
                if ($discount->is_applied && $discount->applied_payment_id !== $freshPayment->id) {
                    throw new InvalidArgumentException('Desconto já aplicado a outro pagamento.');
                }

                $originalAmount = $baseAmountInCents;

                if ($discount->type === DiscountType::Percentage) {
                    $percentage = (int) $discount->value_in_cents;
                    $discountInCents = (int) floor(($baseAmountInCents * $percentage) / 100);
                } else {
                    $discountInCents = (int) $discount->value_in_cents;
                }

                $discountInCents = max(0, $discountInCents);
                $newAmount = max(0, $baseAmountInCents - $discountInCents);

                $currentMetadata['discount'] = [
                    'discount_id' => $discount->id,
                    'original_amount_in_cents' => $baseAmountInCents,
                    'discount_in_cents' => $discountInCents,
                ];

                $freshPayment->amount_in_cents = $newAmount;
                $freshPayment->metadata = $currentMetadata;
                $freshPayment->save();

                $discount->applied_payment_id = $freshPayment->id;
                $discount->applied_at = $discount->applied_at ?? Carbon::now();
                $discount->save();
            } else {
                // Caso 2 — Desconto inexistente ou expirado
                if ($discount !== null && $discount->is_expired) {
                    if ($discount->applied_payment_id === $freshPayment->id) {
                        $discount->applied_payment_id = null;
                        $discount->applied_at = null;
                        $discount->save();
                    }
                }

                if (isset($currentMetadata['discount'])) {
                    $metaDiscountId = $currentMetadata['discount']['discount_id'] ?? null;
                    if ($metaDiscountId && ($discount === null || $discount->id !== $metaDiscountId)) {
                        $oldDiscount = Discount::query()->lockForUpdate()->find($metaDiscountId);
                        if ($oldDiscount && $oldDiscount->applied_payment_id === $freshPayment->id) {
                            $oldDiscount->applied_payment_id = null;
                            $oldDiscount->applied_at = null;
                            $oldDiscount->save();
                        }
                    }
                    unset($currentMetadata['discount']);
                }

                $freshPayment->metadata = empty($currentMetadata) ? null : $currentMetadata;
                $freshPayment->amount_in_cents = $baseAmountInCents;
                $freshPayment->save();
            }

            $payment->refresh();

            return $freshPayment;
        });
    }

    /**
     * Remove an applied or pending discount, restoring the payment amount.
     *
     * @throws InvalidArgumentException
     */
    public function remove(
        Discount $discount,
        ?CentralUser $remover = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $centralConnection = config('tenancy.database.central_connection');

        DB::connection($centralConnection)->transaction(function () use (
            $discount,
            $remover,
            $ipAddress,
            $userAgent
        ): void {
            /** @var Discount $freshDiscount */
            $freshDiscount = Discount::query()
                ->lockForUpdate()
                ->findOrFail($discount->id);

            $payment = $freshDiscount->appliedPayment;

            if ($payment !== null) {
                if ($payment->status !== PaymentStatus::Pending) {
                    throw new InvalidArgumentException('Não é possível remover desconto de uma fatura que já foi liquidada ou cancelada.');
                }

                $metadata = $payment->metadata ?? [];
                $originalAmount = $metadata['discount']['original_amount_in_cents']
                    ?? $payment->tenant?->subscriptions()->latest()->first()?->price_in_cents
                    ?? null;

                if ($originalAmount !== null) {
                    $payment->amount_in_cents = (int) $originalAmount;
                }

                unset($metadata['discount']);
                $payment->metadata = empty($metadata) ? null : $metadata;
                $payment->save();
            }

            AuditLog::query()->create([
                'central_user_id' => $remover?->id,
                'tenant_id' => $freshDiscount->tenant_id,
                'action' => 'tenant.discount.removed',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'discount_id' => $freshDiscount->id,
                    'type' => $freshDiscount->type->value,
                    'value_in_cents' => $freshDiscount->value_in_cents,
                    'applied_payment_id' => $payment?->id,
                ],
            ]);

            $freshDiscount->delete();
        });
    }

    /**
     * Update an applied discount and recalculate payment amount.
     *
     * @throws InvalidArgumentException
     */
    public function update(
        Discount $discount,
        DiscountType $type,
        int $valueInCents,
        ?CarbonInterface $expiresAt = null,
        ?CentralUser $updater = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Discount {
        if ($valueInCents <= 0) {
            throw new InvalidArgumentException('O valor do desconto deve ser maior que zero.');
        }

        if ($type === DiscountType::Percentage && $valueInCents > 100) {
            throw new InvalidArgumentException('O percentual não pode ser maior que 100%.');
        }

        if ($expiresAt && Carbon::instance($expiresAt)->isPast()) {
            throw new InvalidArgumentException('O mês de expiração deve ser no presente ou futuro.');
        }

        $centralConnection = config('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use (
            $discount,
            $type,
            $valueInCents,
            $expiresAt,
            $updater,
            $ipAddress,
            $userAgent
        ): Discount {
            /** @var Discount $freshDiscount */
            $freshDiscount = Discount::query()
                ->lockForUpdate()
                ->findOrFail($discount->id);

            $payment = $freshDiscount->appliedPayment;

            if ($payment !== null) {
                if ($payment->status !== PaymentStatus::Pending) {
                    throw new InvalidArgumentException('Não é possível alterar desconto de uma fatura que já foi liquidada ou cancelada.');
                }

                $metadata = $payment->metadata ?? [];
                $originalAmount = (int) ($metadata['discount']['original_amount_in_cents'] ?? $payment->amount_in_cents);

                if ($type === DiscountType::Percentage) {
                    $discountInCents = (int) floor(($originalAmount * $valueInCents) / 100);
                } else {
                    $discountInCents = $valueInCents;
                }

                $discountInCents = max(0, $discountInCents);
                $newAmount = max(0, $originalAmount - $discountInCents);

                $metadata['discount'] = [
                    'discount_id' => $freshDiscount->id,
                    'original_amount_in_cents' => $originalAmount,
                    'discount_in_cents' => $discountInCents,
                ];

                $payment->amount_in_cents = $newAmount;
                $payment->metadata = $metadata;
                $payment->save();
            }

            $freshDiscount->type = $type;
            $freshDiscount->value_in_cents = $valueInCents;
            $freshDiscount->expires_at = $expiresAt ? Carbon::instance($expiresAt) : null;
            $freshDiscount->save();

            AuditLog::query()->create([
                'central_user_id' => $updater?->id,
                'tenant_id' => $freshDiscount->tenant_id,
                'action' => 'tenant.discount.updated',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'discount_id' => $freshDiscount->id,
                    'type' => $type->value,
                    'value_in_cents' => $valueInCents,
                    'applied_payment_id' => $payment?->id,
                ],
            ]);

            return $freshDiscount;
        });
    }
}
