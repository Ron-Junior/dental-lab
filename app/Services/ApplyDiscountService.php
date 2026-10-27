<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\PaymentStatus;
use App\Models\Discount;
use App\Models\Payment;
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
}
