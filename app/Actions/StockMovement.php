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

        return DB::transaction(function () use ($product, $type, $reason, $totalQuantity, $unitCost, $data) {
            $isIncoming = $type === StockMovementsTypes::In || in_array($reason, StockMovementsReasons::in(), true);
            $isOutgoing = $type === StockMovementsTypes::Out || in_array($reason, StockMovementsReasons::out(), true);

            // Validação de estoque total do produto para saídas
            if ($isOutgoing && (float) $product->current_stock < $totalQuantity) {
                throw ValidationException::withMessages([
                    'form.amount' => "Estoque insuficiente. O produto possui apenas {$product->current_stock} {$product->unit_of_measure?->value} disponíveis.",
                ]);
            }

            $movementsCreated = [];

            if ($product->has_batches || ! empty($data['batchNumber']) || ! empty($data['stockBatchId'])) {
                if ($isIncoming) {
                    $stockBatch = null;
                    $batchNumber = $data['batchNumber'] ?? null;
                    $expirationDate = $data['expirationDate'] ?? null;

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
                        'unit_cost' => $unitCost,
                    ]);
                } elseif ($isOutgoing) {
                    $remainingQuantityToDeduct = $totalQuantity;

                    // Busca os lotes disponíveis. Se o usuário enviou um lote específico, coloca ele em primeiro lugar na fila.
                    $batchQuery = StockBatch::where('product_id', $product->id)
                        ->where('current_quantity', '>', 0);

                    if (! empty($data['stockBatchId'])) {
                        $batchQuery->orderByRaw("id = ? DESC", [$data['stockBatchId']]);
                    } elseif (! empty($data['batchNumber'])) {
                        $batchQuery->orderByRaw("batch_number = ? DESC", [trim($data['batchNumber'])]);
                    }

                    // Ordena o restante por data de vencimento (FIFO - Primeiro a vencer sai primeiro)
                    $batches = $batchQuery->orderBy('expiration_date', 'asc')->get();

                    $totalAvailableInBatches = $batches->sum('current_quantity');

                    if ($totalAvailableInBatches < $totalQuantity) {
                        throw ValidationException::withMessages([
                            'form.amount' => "Estoque em lotes insuficiente. Disponível em lotes: {$totalAvailableInBatches}.",
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

                        // Cria uma movimentação individual para o lote atual
                        $movementsCreated[] = ModelsStockMovement::create([
                            'product_id' => $product->id,
                            'stock_batch_id' => $batch->id,
                            'created_by_user_id' => Auth::id(),
                            'type' => $type,
                            'reason' => $reason,
                            'external_identifier' => $data['externalIdentifier'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'quantity' => $deductFromThisBatch,
                            'unit_cost' => $unitCost ?? $batch->cost_price,
                        ]);

                        $remainingQuantityToDeduct -= $deductFromThisBatch;
                    }
                }
            } else {
                // Produto sem controle de lotes
                $movementsCreated[] = ModelsStockMovement::create([
                    'product_id' => $product->id,
                    'stock_batch_id' => null,
                    'created_by_user_id' => Auth::id(),
                    'type' => $type,
                    'reason' => $reason,
                    'external_identifier' => $data['externalIdentifier'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'quantity' => $totalQuantity,
                    'unit_cost' => $unitCost,
                ]);
            }

            // Atualiza o estoque geral do produto
            if ($isIncoming) {
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