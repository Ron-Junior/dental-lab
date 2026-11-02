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
     *     dentist_request_id: int|string|null,
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
     * @return ModelsStockMovement[]|ModelsStockMovement
     */
    public static function handle(int|Product $product, array $data): array|ModelsStockMovement
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

        $isIncoming = $type === StockMovementsTypes::In
            || ($type === StockMovementsTypes::Adjustment && $reason && in_array($reason, StockMovementsReasons::in(), true));
        $isOutgoing = $type === StockMovementsTypes::Out
            || ($type === StockMovementsTypes::Adjustment && $reason && in_array($reason, StockMovementsReasons::out(), true));

        if ($type === StockMovementsTypes::In && $reason !== null && in_array($reason, StockMovementsReasons::out(), true)) {
            $isIncoming = false;
        }
        if ($type === StockMovementsTypes::Out && $reason !== null && in_array($reason, StockMovementsReasons::in(), true)) {
            $isOutgoing = false;
        }

        if ((! $isIncoming && ! $isOutgoing) || ($isIncoming && $isOutgoing)) {
            throw ValidationException::withMessages([
                'form.reason' => 'O motivo selecionado é incompatível com o tipo de movimentação.',
                'reason' => 'O motivo selecionado é incompatível com o tipo de movimentação.',
            ]);
        }

        return DB::transaction(function () use ($product, $type, $reason, $totalQuantity, $unitCost, $data, $isIncoming, $isOutgoing) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            // Validação de estoque total do produto para saídas
            if ($isOutgoing && (float) $product->current_stock < $totalQuantity) {
                throw ValidationException::withMessages([
                    'form.amount' => "Estoque insuficiente. O produto possui apenas {$product->current_stock} {$product->unit_of_measure?->value} disponíveis.",
                    'amount' => "Estoque insuficiente. O produto possui apenas {$product->current_stock} {$product->unit_of_measure?->value} disponíveis.",
                ]);
            }

            $movementsCreated = [];

            if ($product->has_batches || ! empty($data['batchNumber']) || ! empty($data['stockBatchId'])) {
                if ($isIncoming) {
                    $stockBatch = null;
                    $batchNumber = ! empty($data['batchNumber']) ? trim($data['batchNumber']) : null;
                    $stockBatchId = ! empty($data['stockBatchId']) ? $data['stockBatchId'] : null;
                    $expirationDate = $data['expirationDate'] ?? null;

                    if ($stockBatchId) {
                        $stockBatch = StockBatch::where('product_id', $product->id)
                            ->whereKey($stockBatchId)
                            ->lockForUpdate()
                            ->first();
                    }

                    if (! $stockBatch && $batchNumber) {
                        $stockBatch = StockBatch::where('product_id', $product->id)
                            ->where('batch_number', $batchNumber)
                            ->lockForUpdate()
                            ->first();
                    }

                    if ($stockBatch) {
                        if ($unitCost !== null) {
                            if ((float) $stockBatch->current_quantity > 0 && $stockBatch->cost_price !== null) {
                                $currentBatchQty = (float) $stockBatch->current_quantity;
                                $currentBatchCost = (float) $stockBatch->cost_price;
                                $stockBatch->cost_price = round(
                                    (($currentBatchQty * $currentBatchCost) + ($totalQuantity * $unitCost)) / ($currentBatchQty + $totalQuantity),
                                    2
                                );
                            } else {
                                $stockBatch->cost_price = $unitCost;
                            }
                        }
                        $stockBatch->initial_quantity = round((float) $stockBatch->initial_quantity + $totalQuantity, 2);
                        $stockBatch->current_quantity = round((float) $stockBatch->current_quantity + $totalQuantity, 2);
                        if ($expirationDate) {
                            $stockBatch->expiration_date = $expirationDate;
                        }
                        $stockBatch->save();
                    } elseif ($batchNumber) {
                        $stockBatch = new StockBatch([
                            'product_id' => $product->id,
                            'batch_number' => $batchNumber,
                            'initial_quantity' => $totalQuantity,
                            'current_quantity' => $totalQuantity,
                            'expiration_date' => $expirationDate ?: now()->addYear(),
                            'cost_price' => $unitCost ?? $product->unit_cost,
                        ]);
                        $stockBatch->save();
                    } elseif ($product->has_batches) {
                        throw ValidationException::withMessages([
                            'form.batchNumber' => 'O lote é obrigatório para este produto.',
                            'batchNumber' => 'O lote é obrigatório para este produto.',
                        ]);
                    }

                    $movementUnitCost = $unitCost ?? $stockBatch?->cost_price ?? $product->unit_cost;

                    $movementsCreated[] = ModelsStockMovement::create([
                        'dentist_request_id' => $data['dentist_request_id'] ?? null,
                        'product_id' => $product->id,
                        'stock_batch_id' => $stockBatch?->id,
                        'created_by_user_id' => Auth::id(),
                        'type' => $type,
                        'reason' => $reason,
                        'external_identifier' => $data['externalIdentifier'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'quantity' => $totalQuantity,
                        'unit_cost' => $movementUnitCost,
                    ]);
                } elseif ($isOutgoing) {
                    $remainingQuantityToDeduct = $totalQuantity;

                    // Busca os lotes disponíveis com bloqueio pessimista
                    $batchQuery = StockBatch::where('product_id', $product->id)
                        ->where('current_quantity', '>', 0)
                        ->lockForUpdate();

                    if (! empty($data['stockBatchId'])) {
                        $batchQuery->orderByRaw('id = ? DESC', [$data['stockBatchId']]);
                    } elseif (! empty($data['batchNumber'])) {
                        $batchQuery->orderByRaw('batch_number = ? DESC', [trim($data['batchNumber'])]);
                    }

                    if ($reason === StockMovementsReasons::ServiceConsumption) {
                        $batchQuery->where(function ($q) {
                            $q->whereNull('expiration_date')->orWhereDate('expiration_date', '>=', today());
                        });
                    }

                    // Ordena por data de vencimento (FEFO - Primeiro a vencer sai primeiro)
                    $batches = $batchQuery->orderBy('expiration_date', 'asc')->get();

                    $totalAvailableInBatches = (float) $batches->sum('current_quantity');

                    if ($totalAvailableInBatches < $totalQuantity) {
                        throw ValidationException::withMessages([
                            'form.amount' => "Estoque em lotes insuficiente. Disponível em lotes: {$totalAvailableInBatches}.",
                            'amount' => "Estoque em lotes insuficiente. Disponível em lotes: {$totalAvailableInBatches}.",
                        ]);
                    }

                    foreach ($batches as $batch) {
                        if ($remainingQuantityToDeduct <= 0) {
                            break;
                        }

                        $batchAvailable = (float) $batch->current_quantity;
                        $deductFromThisBatch = min($batchAvailable, $remainingQuantityToDeduct);

                        // Baixa a quantidade do lote
                        $batch->current_quantity = round($batchAvailable - $deductFromThisBatch, 2);
                        $batch->save();

                        // Custo unitário herdado do lote ou do produto como fallback
                        $batchUnitCost = $unitCost ?? $batch->cost_price ?? $product->unit_cost;

                        // Cria uma movimentação individual para o lote atual
                        $movementsCreated[] = ModelsStockMovement::create([
                            'dentist_request_id' => $data['dentist_request_id'] ?? null,
                            'product_id' => $product->id,
                            'stock_batch_id' => $batch->id,
                            'created_by_user_id' => Auth::id(),
                            'type' => $type,
                            'reason' => $reason,
                            'external_identifier' => $data['externalIdentifier'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'quantity' => $deductFromThisBatch,
                            'unit_cost' => $batchUnitCost,
                        ]);

                        $remainingQuantityToDeduct -= $deductFromThisBatch;
                    }
                }
            } else {
                // Produto sem controle de lotes
                $movementUnitCost = $unitCost ?? $product->unit_cost;

                $movementsCreated[] = ModelsStockMovement::create([
                    'dentist_request_id' => $data['dentist_request_id'] ?? null,
                    'product_id' => $product->id,
                    'stock_batch_id' => null,
                    'created_by_user_id' => Auth::id(),
                    'type' => $type,
                    'reason' => $reason,
                    'external_identifier' => $data['externalIdentifier'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'quantity' => $totalQuantity,
                    'unit_cost' => $movementUnitCost,
                ]);
            }

            // Atualiza o estoque geral e custo médio do produto
            if ($isIncoming) {
                if ($unitCost !== null && $unitCost > 0) {
                    $currentStockBefore = max(0, (float) $product->current_stock);
                    $currentUnitCost = $product->unit_cost;

                    if ($currentStockBefore > 0 && $currentUnitCost !== null) {
                        $product->unit_cost = round(
                            (($currentStockBefore * $currentUnitCost) + ($totalQuantity * $unitCost)) / ($currentStockBefore + $totalQuantity),
                            4
                        );
                    } else {
                        $product->unit_cost = $unitCost;
                    }
                }

                $product->current_stock = (float) $product->current_stock + $totalQuantity;
            } elseif ($isOutgoing) {
                $product->current_stock = max(0, (float) $product->current_stock - $totalQuantity);
            }
            $product->save();

            // Retorna um único registro se houver apenas uma movimentação, ou o array de movimentações caso tenha dividido os lotes
            return count($movementsCreated) === 1 ? $movementsCreated[0] : $movementsCreated;
        });
    }
}
