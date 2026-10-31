<?php

namespace Tests\Feature;

use App\Actions\CancelService;
use App\Enums\CommissionTypes;
use App\Enums\Rules;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\RequestService;
use App\Models\RequestServiceConsumption;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceStep;
use App\Models\StockBatch;
use App\Models\User;
use App\Notifications\CompletedRequestNotification;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerCommissionsAndDemandWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_demand_with_fractional_fixed_commission_persists_exact_decimals_and_reports_cents(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $partner = Partner::factory()->create();
        $dentist = $this->dentistWithUser();

        $step = ServiceStep::factory()->create();
        $step->partners()->attach($partner->id, [
            'commission' => '45.50',
            'commission_type' => CommissionTypes::Fixed->value,
        ]);

        $service = Service::create([
            'name' => 'Prótese Fixa Fracionada',
            'price' => 500.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create([
            'dentist_id' => $dentist->id,
        ]);

        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 500.00,
            'quantity' => 1,
        ]);

        $demand = $requestService->partnerDemands()->firstOrFail();
        $demand->update(['partner_id' => $partner->id]);

        Livewire::actingAs($partner->user)
            ->test('partners.start-demand')
            ->call('openModal', $demand->id)
            ->assertSet('partnerCommissionValue', 45.50)
            ->assertSet('partnerCommissionType', CommissionTypes::Fixed->value)
            ->assertSee('R$ 45,50')
            ->call('startDemand')
            ->assertDispatched('demand::refresh');

        $demand->refresh();
        $this->assertEquals(45.50, (float) $demand->partner_commission);
        $this->assertNotNull($demand->started_at);

        $demand->update(['ended_at' => now()]);

        Livewire::actingAs($owner)
            ->test('pages.reports.index')
            ->set('activeReport', 'partners')
            ->assertSee('R$ 45,50')
            ->tap(function ($component) {
                $this->assertSame(4550, $component->instance()->profitabilitySummary['partner_commissions_cents']);
            });
    }

    public function test_starting_demand_with_fractional_percentage_commission_calculates_and_reports_correctly(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $partner = Partner::factory()->create();
        $dentist = $this->dentistWithUser();

        $step = ServiceStep::factory()->create();
        $step->partners()->attach($partner->id, [
            'commission' => '12.50',
            'commission_type' => CommissionTypes::Percentage->value,
        ]);

        $service = Service::create([
            'name' => 'Faceta em Porcelana',
            'price' => 200.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create([
            'dentist_id' => $dentist->id,
        ]);

        // unit_price = 200.00 (20000 cents), quantity = 2 -> total = 40000 cents
        // 12.50% of 40000 = 5000 cents (R$ 50,00)
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 200.00,
            'quantity' => 2,
        ]);

        $demand = $requestService->partnerDemands()->firstOrFail();
        $demand->update(['partner_id' => $partner->id]);

        Livewire::actingAs($partner->user)
            ->test('partners.start-demand')
            ->call('openModal', $demand->id)
            ->assertSet('partnerCommissionValue', 12.50)
            ->assertSee('12,50%')
            ->call('startDemand');

        $demand->refresh();
        $this->assertEquals(12.50, (float) $demand->partner_commission);
        $demand->update(['ended_at' => now()]);

        Livewire::actingAs($owner)
            ->test('pages.reports.index')
            ->set('activeReport', 'partners')
            ->assertSee('12,50%')
            ->tap(function ($component) {
                $this->assertSame(5000, $component->instance()->profitabilitySummary['partner_commissions_cents']);
            });
    }

    public function test_lab_partner_user_is_authorized_to_see_start_and_complete_buttons_enabled_in_ui(): void
    {
        $partner = Partner::factory()->create();
        $dentist = $this->dentistWithUser();

        $step = ServiceStep::factory()->create();
        $step->partners()->attach($partner->id, [
            'commission' => '50.00',
            'commission_type' => CommissionTypes::Fixed->value,
        ]);

        $service = Service::create([
            'name' => 'Placa de Bruxismo',
            'price' => 300.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 300.00,
            'quantity' => 1,
        ]);

        $demand = $requestService->partnerDemands()->firstOrFail();
        $demand->update(['partner_id' => $partner->id]);

        // Demand is Assigned, Partner can start
        $this->assertTrue($partner->user->can('start', $demand));
        $this->assertFalse($partner->user->can('complete', $demand));

        // When rendered, the partner sees the start button and it is not disabled
        Livewire::actingAs($partner->user)
            ->test('pages.dentists.demands')
            ->assertOk()
            ->assertSee('start-demand::open')
            ->assertDontSee(':disabled="!Auth::user()->can(\'assign\'');

        // Start the demand
        $demand->update([
            'started_at' => now(),
            'partner_commission' => 50.00,
            'partner_commission_type' => CommissionTypes::Fixed->value,
        ]);
        $demand->refresh();

        // Demand is InProgress, Partner can complete
        $this->assertFalse($partner->user->can('start', $demand));
        $this->assertTrue($partner->user->can('complete', $demand));

        Livewire::actingAs($partner->user)
            ->test('pages.dentists.demands')
            ->assertOk()
            ->assertSee('finish-demand::open');
    }

    public function test_start_demand_modal_resilience_when_partner_detached_from_step_in_catalog(): void
    {
        $partner = Partner::factory()->create();
        $dentist = $this->dentistWithUser();

        $step = ServiceStep::factory()->create();
        $step->partners()->attach($partner->id, [
            'commission' => '30.00',
            'commission_type' => CommissionTypes::Fixed->value,
        ]);

        $service = Service::create([
            'name' => 'Prótese Total',
            'price' => 600.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 600.00,
            'quantity' => 1,
        ]);

        $demand = $requestService->partnerDemands()->firstOrFail();
        $demand->update(['partner_id' => $partner->id]);

        // Manager detaches the partner from the step in catalog
        $step->partners()->detach($partner->id);

        Livewire::actingAs($partner->user)
            ->test('partners.start-demand')
            ->call('openModal', $demand->id)
            ->assertSet('partnerDemand', null)
            ->assertSet('partnerCommissionValue', null);
    }

    public function test_finishing_last_demand_automatically_completes_service_and_order_and_notifies_dentist(): void
    {
        Notification::fake();

        $partner = Partner::factory()->create();
        $dentist = $this->dentistWithUser();

        $step = ServiceStep::factory()->create();
        $step->partners()->attach($partner->id, [
            'commission' => '25.00',
            'commission_type' => CommissionTypes::Fixed->value,
        ]);

        $service = Service::create([
            'name' => 'Coroa Metalocerâmica',
            'price' => 450.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 450.00,
            'quantity' => 1,
        ]);

        $demand = $requestService->partnerDemands()->firstOrFail();
        $demand->update([
            'partner_id' => $partner->id,
            'started_at' => now(),
            'partner_commission' => 25.00,
            'partner_commission_type' => CommissionTypes::Fixed->value,
        ]);

        $this->assertNull($requestService->completed_at);
        $this->assertNull($dentistRequest->completed_at);

        Livewire::actingAs($partner->user)
            ->test('partners.finish-demand')
            ->call('openModal', $demand->id)
            ->call('finishDemand')
            ->assertDispatched('demand::refresh')
            ->assertDispatched('requests::refresh');

        $requestService->refresh();
        $dentistRequest->refresh();

        $this->assertNotNull($requestService->completed_at);
        $this->assertNotNull($dentistRequest->completed_at);

        Notification::assertSentTo($dentist->user, CompletedRequestNotification::class);
    }

    public function test_canceling_service_reverts_stock_consumptions_and_cleans_demands_and_handles_parent_order(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();

        $category = ProductCategory::create(['name' => 'Resinas']);
        $product = Product::create([
            'name' => 'Resina Bisacrílica',
            'product_category_id' => $category->id,
            'unit_of_measure' => \App\Enums\UnitMeasure::Unit->value,
            'min_stock' => 5,
            'current_stock' => 10,
            'has_batches' => true,
        ]);

        $batch = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOTE-101',
            'initial_quantity' => 10,
            'current_quantity' => 8,
            'expiration_date' => now()->addMonths(6),
            'cost_price' => 50.00,
        ]);

        $step = ServiceStep::factory()->create();
        $service = Service::create([
            'name' => 'Bloco em Resina',
            'price' => 250.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 250.00,
            'quantity' => 1,
        ]);

        $consumption = RequestServiceConsumption::create([
            'request_service_id' => $requestService->id,
            'product_id' => $product->id,
            'stock_batch_id' => $batch->id,
            'quantity_used' => 2.00,
        ]);

        $this->assertEquals(8, $batch->fresh()->current_quantity);
        $this->assertDatabaseHas('partner_demands', ['request_service_id' => $requestService->id]);

        Livewire::actingAs($owner)
            ->test('pages.services.planned')
            ->dispatch('service::canceled', $requestService->id)
            ->assertDispatched('requests::refresh')
            ->assertDispatched('demand::refresh');

        $this->assertDatabaseMissing('request_services', ['id' => $requestService->id]);
        $this->assertDatabaseMissing('partner_demands', ['request_service_id' => $requestService->id]);
        $this->assertDatabaseMissing('request_service_consumptions', ['id' => $consumption->id]);

        // Stock batch refunded by 2 units (8 + 2 = 10)
        $this->assertEquals(10, (float) $batch->fresh()->current_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovementsTypes::In->value,
            'reason' => StockMovementsReasons::Devolution->value,
            'quantity' => 2.00,
        ]);

        // Since it was the only service, the empty parent order was deleted
        $this->assertDatabaseMissing('dentist_requests', ['id' => $dentistRequest->id]);
    }

    public function test_canceling_already_completed_or_started_service_is_blocked(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();

        $step = ServiceStep::factory()->create();
        $service = Service::create([
            'name' => 'Prótese Flexível',
            'price' => 400.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 400.00,
            'quantity' => 1,
            'completed_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não é possível cancelar um serviço já concluído.');

        CancelService::handle($requestService);
    }

    public function test_canceling_service_with_started_demands_is_blocked(): void
    {
        $dentist = $this->dentistWithUser();
        $partner = Partner::factory()->create();

        $step = ServiceStep::factory()->create();
        $service = Service::create([
            'name' => 'Prótese Sobre Implante',
            'price' => 700.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 700.00,
            'quantity' => 1,
        ]);

        $demand = $requestService->partnerDemands()->firstOrFail();
        $demand->update([
            'partner_id' => $partner->id,
            'started_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não é possível cancelar um serviço cujas etapas já foram iniciadas ou concluídas.');

        CancelService::handle($requestService);
    }

    public function test_planned_services_page_renders_safely_with_zero_steps_service_without_division_by_zero(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();

        $serviceWithoutSteps = Service::create([
            'name' => 'Apenas Modelo de Estudo',
            'price' => 50.00,
        ]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $serviceWithoutSteps->id,
            'unit_price' => 50.00,
            'quantity' => 1,
        ]);

        Livewire::actingAs($owner)
            ->test('pages.services.planned')
            ->assertOk()
            ->assertSee('Apenas Modelo de Estudo')
            ->assertSee('0%');
    }

    private function userWithRule(Rules $rule): User
    {
        $ruleModel = Rule::firstOrCreate(['name' => $rule->value]);

        return User::factory()->for($ruleModel)->create();
    }

    private function dentistWithUser(): Dentist
    {
        return Dentist::factory()
            ->for($this->userWithRule(Rules::Dentist))
            ->create();
    }
}
