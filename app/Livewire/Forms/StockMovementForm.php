<?php

namespace App\Livewire\Forms;

use App\Actions\StockMovement;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\StockMovement as ModelsStockMovement;
use Livewire\Form;

class StockMovementForm extends Form
{
    public ?int $productId = null;

    public ?float $amount = null;

    public string|StockMovementsTypes|null $movementType = 'in';

    public string|StockMovementsReasons|null $reason = null;

    public ?string $externalIdentifier = null;

    public ?string $cost = null;

    public ?string $batchNumber = null;

    public ?int $stockBatchId = null;

    public ?string $expirationDate = null;

    public ?float $quantityPerUnit = null;

    public ?string $notes = null;

    public bool $hasBatches = false;

    public function getTypeEnum(): ?StockMovementsTypes
    {
        if ($this->movementType instanceof StockMovementsTypes) {
            return $this->movementType;
        }

        return $this->movementType ? StockMovementsTypes::tryFrom((string) $this->movementType) : null;
    }

    public function getReasonEnum(): ?StockMovementsReasons
    {
        if ($this->reason instanceof StockMovementsReasons) {
            return $this->reason;
        }

        return $this->reason ? StockMovementsReasons::tryFrom((string) $this->reason) : null;
    }

    public function getAvailableReasons(): array
    {
        $type = $this->getTypeEnum();

        return $type ? $type->getReasons() : [];
    }

    public function rules(): array
    {
        $type = $this->getTypeEnum();
        $isIncoming = $type === StockMovementsTypes::In;
        $isOutgoing = $type === StockMovementsTypes::Out;

        $rules = [
            'productId' => 'required|exists:products,id',
            'movementType' => 'required',
            'reason' => 'required',
            'amount' => 'required|numeric|gt:0',
            'quantityPerUnit' => 'nullable|numeric|min:0',
            'externalIdentifier' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ];

        if ($isIncoming) {
            $rules['cost'] = 'nullable|string';
            if ($this->hasBatches) {
                $rules['batchNumber'] = 'required|string|max:50';
                $rules['expirationDate'] = 'required|date';
            }
        } elseif ($isOutgoing && $this->hasBatches) {
            $rules['stockBatchId'] = 'required|exists:stock_batches,id';
        }

        return $rules;
    }

    public function validationAttributes(): array
    {
        return [
            'productId' => 'produto',
            'movementType' => 'tipo de movimentação',
            'reason' => 'motivo',
            'amount' => 'quantidade',
            'quantityPerUnit' => 'quantidade por unidade',
            'externalIdentifier' => 'identificador externo',
            'cost' => 'preço total',
            'batchNumber' => 'número do lote',
            'expirationDate' => 'data de validade',
            'stockBatchId' => 'lote',
            'notes' => 'observações',
        ];
    }

    public function save(): ModelsStockMovement
    {
        $this->validate();

        return StockMovement::handle($this->productId, [
            'movementType' => $this->getTypeEnum(),
            'reason' => $this->getReasonEnum(),
            'amount' => $this->amount,
            'quantityPerUnit' => $this->quantityPerUnit,
            'cost' => $this->cost,
            'batchNumber' => $this->batchNumber,
            'stockBatchId' => $this->stockBatchId,
            'expirationDate' => $this->expirationDate,
            'externalIdentifier' => $this->externalIdentifier,
            'notes' => $this->notes,
            'hasBatches' => $this->hasBatches,
        ]);
    }
}
