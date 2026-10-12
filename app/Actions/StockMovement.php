<?php

namespace App\Actions;

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement as ModelsStockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovement
{
    /**
     * Handle stock movement creation and update inventory and batches.
     *
     * @param  array{
     *     movementType: StockMovementsTypes|string,
     *     reason: StockMovementsReasons|string,
     *     amount: float|int|string,
     *     quantityPerUnit?: float|int|string|null,
     *     cost?: float|int|string|null,
     *     batchNumber?: string|null,
     *     stockBatchId?: int|string|null,
     *     expirationDate?: string|null,
     *     externalIdentifier?: string|null,
     *     notes?: string|null,
     *     hasBatches?: bool|null,
     * }  $data
     */
    public static function handle(int|Product $product, array $data): ModelsStockMovement
    {
        if (! $product instanceof Product) {
            $product = Product::findOrFail($product);
        }

        $type = $data['movementType'] instanceof StockMovementsTypes
            ? $data['movementType']
            : StockMovementsTypes::from($data['movementType']);

        $reason = null;
        if (! empty($data['reason'])) {
            $reason = $data['reason'] instanceof StockMovementsReasons
                ? $data['reason']
                : StockMovementsReasons::from($data['reason']);
        }

        $amount = (float) ($data['amount'] ?? 0);
        $quantityPerUnit = ! empty($data['quantityPerUnit']) ? (float) $data['quantityPerUnit'] : null;

        $totalQuantity = ($quantityPerUnit && $quantityPerUnit > 0)
            ? round($amount * $quantityPerUnit, 2)
            : round($amount, 2);

        if ($totalQuantity <= 0) {
            throw ValidationException::withMessages([
                'form.amount' => 'A quantidade deve ser maior que zero.',
            ]);
        }

        $unitCost = null;
        if (! empty($data['cost'])) {
            $rawCost = $data['cost'];
            if (is_string($rawCost)) {
                $rawCost = str_replace(['R$', ' '], '', $rawCost);
                if (str_contains($rawCost, ',')) {
                    $rawCost = str_replace('.', '', $rawCost);
                    $rawCost = str_replace(',', '.', $rawCost);
                }
            }
            $cleanCost = (float) $rawCost;

            if ($cleanCost > 0 && $totalQuantity > 0) {
                $unitCost = round($cleanCost / $totalQuantity, 4);
            }
        }

        return DB::transaction(function () use ($product, $type, $reason, $totalQuantity, $unitCost, $data) {
            $stockBatch = null;
            $batchNumber = $data['batchNumber'] ?? null;
            $stockBatchId = $data['stockBatchId'] ?? null;
            $expirationDate = $data['expirationDate'] ?? null;

            if ($product->has_batches || ! empty($batchNumber) || ! empty($stockBatchId)) {
                if ($type === StockMovementsTypes::In) {
                    if (! empty($batchNumber)) {
                        $stockBatch = StockBatch::firstOrNew([
                            'product_id' => $product->id,
                            'batch_number' => trim($batchNumber),
                        ]);

                        if (! $stockBatch->exists) {
                            $stockBatch->initial_quantity = $totalQuantity;
                            $stockBatch->current_quantity = $totalQuantity;
                            $stockBatch->expiration_date = $expirationDate ?: now()->addYear();
                            $stockBatch->cost_price = $unitCost;
                        } else {
                            $stockBatch->current_quantity = (float) $stockBatch->current_quantity + $totalQuantity;
                            if ($expirationDate) {
                                $stockBatch->expiration_date = $expirationDate;
                            }
                            if ($unitCost !== null) {
                                $stockBatch->cost_price = $unitCost;
                            }
                        }
                        $stockBatch->save();
                    }
                } else {
                    if (! empty($stockBatchId)) {
                        $stockBatch = StockBatch::where('product_id', $product->id)->find($stockBatchId);
                    } elseif (! empty($batchNumber)) {
                        $stockBatch = StockBatch::where('product_id', $product->id)->where('batch_number', trim($batchNumber))->first();
                    }

                    if ($stockBatch) {
                        $isOutgoing = $type === StockMovementsTypes::Out || in_array($reason, StockMovementsReasons::out(), true);
                        if ($isOutgoing) {
                            if ((float) $stockBatch->current_quantity < $totalQuantity) {
                                throw ValidationException::withMessages([
                                    'form.amount' => "O lote selecionado ({$stockBatch->batch_number}) possui apenas {$stockBatch->current_quantity} em estoque.",
                                ]);
                            }
                            $stockBatch->current_quantity = max(0, (float) $stockBatch->current_quantity - $totalQuantity);
                        } else {
                            $stockBatch->current_quantity = (float) $stockBatch->current_quantity + $totalQuantity;
                        }
                        $stockBatch->save();
                    }
                }
            }

            // Determine if increasing or decreasing overall stock
            $isIncoming = $type === StockMovementsTypes::In || in_array($reason, StockMovementsReasons::in(), true);
            $isOutgoing = $type === StockMovementsTypes::Out || in_array($reason, StockMovementsReasons::out(), true);

            if ($isIncoming) {
                $product->current_stock = (float) $product->current_stock + $totalQuantity;
            } elseif ($isOutgoing) {
                if ((float) $product->current_stock < $totalQuantity) {
                    throw ValidationException::withMessages([
                        'form.amount' => "Estoque insuficiente. O produto possui apenas {$product->current_stock} {$product->unit_of_measure?->value} disponíveis.",
                    ]);
                }
                $product->current_stock = max(0, (float) $product->current_stock - $totalQuantity);
            }
            $product->save();

            return ModelsStockMovement::create([
                'product_id' => $product->id,
                'stock_batch_id' => $stockBatch?->id,
                'created_by_user_id' => Auth::id(),
                'type' => $type,
                'reason' => $reason,
                'external_identifier' => $data['externalIdentifier'] ?? null,
                'notes' => $data['notes'] ?? null,
                'quantity' => $totalQuantity,
                'unit_cost' => $unitCost,
            ]);
        });
    }
}
