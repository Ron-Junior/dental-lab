<?php

namespace App\Livewire\Forms;

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use Livewire\Attributes\Validate;
use Livewire\Form;

class StockMovementForm extends Form
{
    #[Validate('required|exists:products,id', 'produto')]
    public ?int $productId = null;

    #[Validate('required|numeric|min:0', 'quantidade')]
    public ?float $amount = null;

    #[Validate(['required'], 'tipo de movimentação')]
    public ?StockMovementsTypes $movementType = StockMovementsTypes::In;

    #[Validate('required', 'motivo')]
    public ?StockMovementsReasons $reason = null;

    #[Validate('nullable|string|max:255', 'identificador externo')]
    public ?string $externalIdentifier = null;

    #[Validate('nullable|required_if:hasBatches,true|numeric|min:0', 'custo')]
    public ?string $cost = null;

    #[Validate('nullable|required_if:hasBatches,true|string', 'lote')]
    public ?string $batchNumber = null;

    #[Validate('nullable|required_if:hasBatches,true|date', 'data de validade')]
    public ?string $expirationDate = null;

    #[Validate('nullable|numeric|min:0', 'quantidade por unidade')]
    public ?float $quantityPerUnit = null;

    public ?bool $hasBatches = false;
}
