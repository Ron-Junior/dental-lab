<?php

namespace Tests\Feature;

use App\Actions\StockMovement as StockMovementAction;
use App\Enums\Rules;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Enums\UnitMeasure;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Product;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CmvBatchCostAndStockConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_in_and_out_updates_batch_and_product_and_returns_valid_instance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 50.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => true,
        ]);

        $batch = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOTE-ADJ-001',
            'initial_quantity' => 50.00,
            'current_quantity' => 50.00,
            'cost_price' => 10.00,
            'expiration_date' => now()->addMonths(6),
        ]);

        // 1. Ajuste de Entrada (AdjustmentIn) em lote existente
        $adjInMovement = StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::Adjustment,
            'reason' => StockMovementsReasons::AdjustmentIn,
            'amount' => 20.00,
            'stockBatchId' => $batch->id,
            'notes' => 'Sobra de contagem',
        ]);

        $this->assertInstanceOf(StockMovement::class, $adjInMovement);
        $this->assertEquals(StockMovementsTypes::Adjustment, $adjInMovement->type);
        $this->assertEquals(StockMovementsReasons::AdjustmentIn, $adjInMovement->reason);
        $this->assertEquals(10.00, $adjInMovement->unit_cost);

        $product->refresh();
        $batch->refresh();
        $this->assertEquals(70.00, (float) $product->current_stock);
        $this->assertEquals(70.00, (float) $batch->current_quantity);
        $this->assertEquals(70.00, (float) $batch->initial_quantity);

        // 2. Ajuste de Saída (AdjustmentOut) em lote existente
        $adjOutMovement = StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::Adjustment,
            'reason' => StockMovementsReasons::AdjustmentOut,
            'amount' => 15.00,
            'stockBatchId' => $batch->id,
            'notes' => 'Falta no inventário',
        ]);

        $this->assertInstanceOf(StockMovement::class, $adjOutMovement);
        $this->assertEquals(StockMovementsTypes::Adjustment, $adjOutMovement->type);
        $this->assertEquals(StockMovementsReasons::AdjustmentOut, $adjOutMovement->reason);
        $this->assertEquals(10.00, $adjOutMovement->unit_cost);

        $product->refresh();
        $batch->refresh();
        $this->assertEquals(55.00, (float) $product->current_stock);
        $this->assertEquals(55.00, (float) $batch->current_quantity);
    }

    public function test_invalid_type_and_reason_combination_throws_validation_exception(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 10.00,
            'has_batches' => false,
        ]);

        // In com motivo de saída
        $this->expectException(ValidationException::class);
        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'amount' => 5.00,
        ]);
    }

    public function test_out_with_in_reason_throws_validation_exception(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 10.00,
            'has_batches' => false,
        ]);

        $this->expectException(ValidationException::class);
        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 5.00,
        ]);
    }

    public function test_product_without_batches_calculates_and_stores_unit_cost_on_incoming(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 0.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => false,
            'unit_cost' => null,
        ]);

        // Primeira entrada: 100g a R$ 10,00 (Total R$ 1.000,00)
        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 100.00,
            'cost' => '1000,00',
        ]);

        $product->refresh();
        $this->assertEquals(100.00, (float) $product->current_stock);
        $this->assertEquals(10.00, (float) $product->unit_cost);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'unit_cost' => 1000, // 1000 centavos = R$ 10,00
        ]);

        // Segunda entrada: 100g a R$ 20,00 (Total R$ 2.000,00)
        // CMP = ((100 * 10) + (100 * 20)) / 200 = 15.00
        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 100.00,
            'cost' => '2000,00',
        ]);

        $product->refresh();
        $this->assertEquals(200.00, (float) $product->current_stock);
        $this->assertEquals(15.00, (float) $product->unit_cost);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'unit_cost' => 1500, // 1500 centavos = R$ 15,00
        ]);
    }

    public function test_service_consumption_of_product_without_batches_inherits_product_unit_cost_and_completes_reports(): void
    {
        $rule = Rule::firstOrCreate(['name' => Rules::Lab->value]);
        $user = User::factory()->for($rule)->create();
        $this->actingAs($user);

        $dentistRule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);
        $dentistUser = User::factory()->for($dentistRule)->create();
        $dentist = Dentist::factory()->for($dentistUser)->create();

        $service = Service::factory()->create(['name' => 'Prótese Fixa']);
        $request = DentistRequest::factory()->for($dentist)->create();

        RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 200,
            'quantity' => 1,
        ]);

        $product = Product::factory()->create([
            'current_stock' => 10.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => false,
            'unit_cost' => 12.50, // R$ 12,50
        ]);

        // Baixa para consumo na OS sem passar custo
        $movement = StockMovementAction::handle($product, [
            'dentist_request_id' => $request->id,
            'movementType' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'amount' => 2.00,
        ]);

        $this->assertInstanceOf(StockMovement::class, $movement);
        $this->assertEquals(12.50, (float) $movement->unit_cost);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'unit_cost' => 1250, // 1250 centavos
        ]);

        // Verifica exibição no relatório de lucratividade
        $response = $this->get(route('reports.index'));
        $response->assertOk();
        $response->assertSee('Completo');
        $response->assertDontSee('Custo parcial');
    }

    public function test_weighted_average_cost_on_batch_with_subsequent_entries(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 0.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => true,
        ]);

        // Primeira entrada: 100 un a R$ 10,00 no lote LOTE-CMP-01
        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 100.00,
            'cost' => '1000,00',
            'batchNumber' => 'LOTE-CMP-01',
            'expirationDate' => '2028-01-01',
        ]);

        $batch = StockBatch::where('batch_number', 'LOTE-CMP-01')->firstOrFail();
        $this->assertEquals(100.00, (float) $batch->initial_quantity);
        $this->assertEquals(100.00, (float) $batch->current_quantity);
        $this->assertEquals(10.00, (float) $batch->cost_price);

        // Segunda entrada: 100 un a R$ 20,00 no mesmo lote LOTE-CMP-01
        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 100.00,
            'cost' => '2000,00',
            'batchNumber' => 'LOTE-CMP-01',
            'expirationDate' => '2028-01-01',
        ]);

        $batch->refresh();
        $this->assertEquals(200.00, (float) $batch->initial_quantity);
        $this->assertEquals(200.00, (float) $batch->current_quantity);
        $this->assertEquals(15.00, (float) $batch->cost_price);
        $this->assertDatabaseHas('stock_batches', [
            'id' => $batch->id,
            'cost_price' => 1500, // 1500 centavos
        ]);
    }

    public function test_weighted_average_cost_on_batch_after_partial_consumption(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 50.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => true,
        ]);

        // Restavam 50 un a R$ 10,00 de um lote com initial de 100 un
        $batch = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOTE-CMP-02',
            'initial_quantity' => 100.00,
            'current_quantity' => 50.00,
            'cost_price' => 10.00,
            'expiration_date' => now()->addYear(),
        ]);

        // Entram 50 un a R$ 20,00 (Total R$ 1.000,00)
        // CMP = ((50 * 10) + (50 * 20)) / (50 + 50) = 1500 / 100 = 15.00
        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 50.00,
            'cost' => '1000,00',
            'batchNumber' => 'LOTE-CMP-02',
        ]);

        $batch->refresh();
        $this->assertEquals(150.00, (float) $batch->initial_quantity);
        $this->assertEquals(100.00, (float) $batch->current_quantity);
        $this->assertEquals(15.00, (float) $batch->cost_price);
    }

    public function test_quick_stock_out_allows_full_balance_withdrawal(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 5.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => false,
        ]);

        Livewire::test('products.stock.out')
            ->set('selectedProducts', [
                [
                    'id' => $product->id,
                    'quantity' => 5.00, // Retirando exatamente 100% do saldo (5 de 5)
                    'unit_of_measure' => UnitMeasure::Gram->value,
                    'stock_batch_id' => null,
                    'batch_number' => null,
                    'min_stock' => 1.00,
                    'has_batches' => false,
                    'current_stock' => 5.00,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertEquals(0.00, (float) $product->current_stock);
    }

    public function test_quick_stock_out_supports_products_without_batches(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 10.00,
            'unit_of_measure' => UnitMeasure::Unit,
            'has_batches' => false,
        ]);

        Livewire::test('products.stock.out')
            ->set('selectedProducts.0.id', $product->id)
            ->set('selectedProducts.0.quantity', 3)
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertEquals(7.00, (float) $product->current_stock);
    }

    public function test_quick_stock_out_auto_selects_fefo_valid_batch_and_passes_to_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 15.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => true,
        ]);

        // Lote C: Vencido ontem
        $batchExpired = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-EXPIRED',
            'initial_quantity' => 5.00,
            'current_quantity' => 5.00,
            'expiration_date' => now()->subDay(),
        ]);

        // Lote B: Vence em 30 dias
        $batchLater = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-LATER',
            'initial_quantity' => 5.00,
            'current_quantity' => 5.00,
            'expiration_date' => now()->addDays(30),
        ]);

        // Lote A: Vence em 5 dias (FEFO deve selecionar este)
        $batchSooner = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-SOONER',
            'initial_quantity' => 5.00,
            'current_quantity' => 5.00,
            'expiration_date' => now()->addDays(5),
        ]);

        $test = Livewire::test('products.stock.out')
            ->set('selectedProducts.0.id', $product->id);

        // Deve selecionar automaticamente o Lote A
        $selected = $test->get('selectedProducts.0');
        $this->assertEquals($batchSooner->id, $selected['stock_batch_id']);
        $this->assertEquals('LOT-SOONER', $selected['batch_number']);

        $test->set('selectedProducts.0.quantity', 2)
            ->call('save')
            ->assertHasNoErrors();

        $batchSooner->refresh();
        $this->assertEquals(3.00, (float) $batchSooner->current_quantity);
    }

    public function test_stock_movement_form_handles_adjustment_in_and_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 10.00,
            'unit_of_measure' => UnitMeasure::Unit,
            'has_batches' => true,
        ]);

        $batch = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOT-FORM-01',
            'initial_quantity' => 10.00,
            'current_quantity' => 10.00,
            'cost_price' => 5.00,
            'expiration_date' => now()->addMonths(6),
        ]);

        Livewire::test('products.stock.store')
            ->set('form.productId', $product->id)
            ->set('form.movementType', 'adjustment')
            ->set('form.reason', StockMovementsReasons::AdjustmentIn->value)
            ->set('form.amount', 4.0)
            ->set('form.stockBatchId', $batch->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('movements::refresh')
            ->assertDispatched('products::refresh');

        $product->refresh();
        $batch->refresh();
        $this->assertEquals(14.00, (float) $product->current_stock);
        $this->assertEquals(14.00, (float) $batch->current_quantity);
    }
}
