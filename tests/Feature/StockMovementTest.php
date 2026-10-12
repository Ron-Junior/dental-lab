<?php

namespace Tests\Feature;

use App\Actions\StockMovement as StockMovementAction;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Enums\UnitMeasure;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_stock_to_product_without_batches(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 10.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => false,
        ]);

        $movement = StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 500,
            'cost' => '150,00',
            'externalIdentifier' => 'NF-12345',
            'notes' => 'Compra de gesso tipo IV',
        ]);

        $product->refresh();

        $this->assertEquals(510.00, (float) $product->current_stock);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'product_id' => $product->id,
            'type' => StockMovementsTypes::In->value,
            'reason' => StockMovementsReasons::Purchase->value,
            'quantity' => 500.00,
            'external_identifier' => 'NF-12345',
            'created_by_user_id' => $user->id,
        ]);
        $this->assertEquals(0.30, $movement->unit_cost);
    }

    public function test_can_add_stock_with_batch_and_expiration(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 0.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => true,
        ]);

        $movement = StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'amount' => 1000,
            'batchNumber' => 'LOTE-DENTAL-001',
            'expirationDate' => '2028-12-31',
            'cost' => '200,00',
        ]);

        $product->refresh();

        $this->assertEquals(1000.00, (float) $product->current_stock);
        $this->assertDatabaseHas('stock_batches', [
            'product_id' => $product->id,
            'batch_number' => 'LOTE-DENTAL-001',
            'initial_quantity' => 1000.00,
            'current_quantity' => 1000.00,
        ]);

        $batch = StockBatch::where('batch_number', 'LOTE-DENTAL-001')->first();
        $this->assertNotNull($batch);
        $this->assertEquals('2028-12-31', $batch->expiration_date->format('Y-m-d'));
    }

    public function test_can_remove_stock_from_batch(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 500.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => true,
        ]);

        $batch = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOTE-TEST-002',
            'initial_quantity' => 500.00,
            'current_quantity' => 500.00,
            'expiration_date' => '2027-06-30',
        ]);

        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'amount' => 150.00,
            'stockBatchId' => $batch->id,
            'externalIdentifier' => 'OS-102',
        ]);

        $product->refresh();
        $batch->refresh();

        $this->assertEquals(350.00, (float) $product->current_stock);
        $this->assertEquals(350.00, (float) $batch->current_quantity);
    }

    public function test_cannot_remove_more_than_available_stock(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 50.00,
            'unit_of_measure' => UnitMeasure::Gram,
            'has_batches' => false,
        ]);

        $this->expectException(ValidationException::class);

        StockMovementAction::handle($product, [
            'movementType' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::WasteDamage,
            'amount' => 100.00,
        ]);
    }

    public function test_stock_movement_store_component_creates_movement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::factory()->create([
            'current_stock' => 20.00,
            'unit_of_measure' => UnitMeasure::Unit,
            'has_batches' => false,
        ]);

        Livewire::test('products.stock.store')
            ->set('form.productId', $product->id)
            ->set('form.movementType', 'in')
            ->set('form.reason', StockMovementsReasons::Purchase->value)
            ->set('form.amount', 15)
            ->set('form.cost', '150,00')
            ->set('form.externalIdentifier', 'Dental Speed NF 4421')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('movements::refresh')
            ->assertDispatched('products::refresh');

        $product->refresh();
        $this->assertEquals(35.00, (float) $product->current_stock);
    }

    public function test_movements_page_renders_successfully(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('products.movements.index'));
        $response->assertStatus(200);
        $response->assertSee('Gestão de Estoque');
        $response->assertSee('Movimentações do Estoque');
        $response->assertSee('Rastreabilidade');
    }
}
