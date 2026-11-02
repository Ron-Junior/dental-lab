<?php

namespace Tests\Feature;

use App\Actions\CancelDentistRequest;
use App\Enums\DemandStatus;
use App\Enums\Rules;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Enums\UnitMeasure;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Partner;
use App\Models\PartnerDemand;
use App\Models\Product;
use App\Models\RequestService;
use App\Models\RequestServiceConsumption;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceServiceStep;
use App\Models\ServiceStep;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\PartnerDemandCancelledNotification;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRule(Rules $rule, ?string $email = null): User
    {
        $ruleModel = Rule::firstOrCreate(['name' => $rule->value]);

        return User::factory()->for($ruleModel)->create(array_filter([
            'email' => $email,
            'password' => Hash::make('password'),
        ]));
    }

    private function createOrderFixture(): array
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();

        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $partner = Partner::factory()->for($partnerUser)->create();

        $service = Service::create([
            'name' => 'Prótese Fixa',
            'price' => 150.00, // R$ 150,00
        ]);

        $step = ServiceStep::create(['name' => 'Modelagem']);
        $step->partners()->attach($partner->id, [
            'commission' => 5000,
            'commission_type' => 'fixed',
        ]);

        $serviceServiceStep = ServiceServiceStep::create([
            'service_id' => $service->id,
            'service_step_id' => $step->id,
            'order' => 1,
        ]);

        $dentistRequest = DentistRequest::create([
            'dentist_id' => $dentist->id,
            'code' => 'ORD-12345',
        ]);

        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 15000,
            'quantity' => 1,
        ]);

        $partnerDemand = PartnerDemand::create([
            'partner_id' => $partner->id,
            'request_service_id' => $requestService->id,
            'service_service_step_id' => $serviceServiceStep->id,
            'order' => 1,
            'partner_commission' => 5000,
            'partner_commission_type' => 'fixed',
        ]);

        return compact(
            'dentistUser',
            'dentist',
            'partnerUser',
            'partner',
            'service',
            'step',
            'serviceServiceStep',
            'dentistRequest',
            'requestService',
            'partnerDemand'
        );
    }

    public function test_owner_and_lab_manager_can_cancel_pending_or_in_production_order(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $demand = $fixture['partnerDemand'];

        // Marcar etapa como iniciada (ordem em produção)
        $demand->update(['started_at' => now()]);

        $owner = $this->userWithRule(Rules::Owner);
        $labManager = $this->userWithRule(Rules::LabManager);

        $this->assertTrue($owner->can('cancel', $order));
        $this->assertTrue($labManager->can('cancel', $order));

        CancelDentistRequest::handle($order, [
            'category' => 'Erro de lançamento / duplicidade',
            'reason' => 'Pedido duplicado pelo atendente',
        ], $owner);

        $order->refresh();
        $this->assertTrue($order->isCancelled());
        $this->assertSame($owner->id, $order->cancelled_by_user_id);
        $this->assertSame('Erro de lançamento / duplicidade', $order->cancellation_category);
        $this->assertSame('Pedido duplicado pelo atendente', $order->cancellation_reason);
    }

    public function test_dentist_can_cancel_own_order_only_when_pending(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $dentistUser = $fixture['dentistUser'];

        // Pendente (sem started_at e sem consumptions)
        $this->assertTrue($dentistUser->can('cancel', $order));

        // Se uma demanda foi iniciada
        $fixture['partnerDemand']->update(['started_at' => now()]);
        $order->refresh();

        $this->assertTrue($order->hasStartedProduction());
        $this->assertFalse($dentistUser->can('cancel', $order));
    }

    public function test_dentist_cannot_cancel_own_order_if_materials_were_consumed(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $dentistUser = $fixture['dentistUser'];
        $requestService = $fixture['requestService'];

        $product = Product::create([
            'name' => 'Gesso Especial',
            'unit_of_measure' => UnitMeasure::Kilogram,
            'min_stock' => 5,
        ]);

        RequestServiceConsumption::create([
            'request_service_id' => $requestService->id,
            'product_id' => $product->id,
            'quantity_used' => 0.5,
        ]);

        $order->refresh();
        $this->assertTrue($order->hasStartedProduction());
        $this->assertFalse($dentistUser->can('cancel', $order));
    }

    public function test_dentist_cannot_cancel_order_belonging_to_another_dentist(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];

        $anotherDentistUser = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($anotherDentistUser)->create();

        $this->assertFalse($anotherDentistUser->can('cancel', $order));
    }

    public function test_lab_partner_cannot_cancel_order(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $partnerUser = $fixture['partnerUser'];

        $this->assertFalse($partnerUser->can('cancel', $order));
    }

    public function test_cannot_cancel_already_completed_order(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $order->update(['completed_at' => now()]);

        $owner = $this->userWithRule(Rules::Owner);

        $this->assertFalse($owner->can('cancel', $order));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não é possível cancelar uma ordem de serviço já concluída.');

        CancelDentistRequest::handle($order, [
            'category' => 'Outros',
            'reason' => 'Tentativa de cancelar OS finalizada',
        ], $owner);
    }

    public function test_cannot_cancel_already_cancelled_order(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $order->update(['cancelled_at' => now()]);

        $owner = $this->userWithRule(Rules::Owner);

        $this->assertFalse($owner->can('cancel', $order));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Esta ordem de serviço já se encontra cancelada.');

        CancelDentistRequest::handle($order, [
            'category' => 'Outros',
            'reason' => 'Tentativa de cancelar OS cancelada',
        ], $owner);
    }

    public function test_stock_integrity_with_devolution_and_waste_materials(): void
    {
        Notification::fake();

        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $requestService = $fixture['requestService'];
        $owner = $this->userWithRule(Rules::Owner);

        $productDevolution = Product::create([
            'name' => 'Dentes Acrílicos',
            'unit_of_measure' => UnitMeasure::Unit,
            'current_stock' => 10,
            'min_stock' => 2,
        ]);
        $batchDevolution = StockBatch::create([
            'product_id' => $productDevolution->id,
            'batch_number' => 'LOT-DEV-1',
            'initial_quantity' => 20,
            'current_quantity' => 10,
            'cost_price' => 5.00,
            'expiration_date' => now()->addYear(),
        ]);
        $consumptionDevolution = RequestServiceConsumption::create([
            'request_service_id' => $requestService->id,
            'product_id' => $productDevolution->id,
            'stock_batch_id' => $batchDevolution->id,
            'quantity_used' => 2,
        ]);

        $productWaste = Product::create([
            'name' => 'Bloco de Zircônia Customizado',
            'unit_of_measure' => UnitMeasure::Unit,
            'current_stock' => 5,
            'min_stock' => 1,
        ]);
        $batchWaste = StockBatch::create([
            'product_id' => $productWaste->id,
            'batch_number' => 'LOT-WST-1',
            'initial_quantity' => 10,
            'current_quantity' => 5,
            'cost_price' => 80.00,
            'expiration_date' => now()->addYear(),
        ]);
        $consumptionWaste = RequestServiceConsumption::create([
            'request_service_id' => $requestService->id,
            'product_id' => $productWaste->id,
            'stock_batch_id' => $batchWaste->id,
            'quantity_used' => 1,
        ]);

        CancelDentistRequest::handle($order, [
            'category' => 'Desistência do dentista / paciente',
            'reason' => 'Paciente cancelou o procedimento cirúrgico',
            'material_actions' => [
                $consumptionDevolution->id => 'devolution',
                $consumptionWaste->id => 'waste',
            ],
        ], $owner);

        // Produto estornado: saldo de lote e do produto aumentam em 2
        $productDevolution->refresh();
        $batchDevolution->refresh();
        $this->assertEquals(12, $productDevolution->current_stock);
        $this->assertEquals(12, $batchDevolution->current_quantity);

        $devolutionMovement = StockMovement::where('product_id', $productDevolution->id)
            ->where('reason', StockMovementsReasons::Devolution)
            ->first();
        $this->assertNotNull($devolutionMovement);
        $this->assertEquals(StockMovementsTypes::In, $devolutionMovement->type);
        $this->assertEquals(2, $devolutionMovement->quantity);
        $this->assertEquals($order->id, $devolutionMovement->dentist_request_id);

        // Produto descartado: estoque permanece inalterado
        $productWaste->refresh();
        $batchWaste->refresh();
        $this->assertEquals(5, $productWaste->current_stock);
        $this->assertEquals(5, $batchWaste->current_quantity);

        $wasteDevolutionMovement = StockMovement::where('product_id', $productWaste->id)
            ->where('reason', StockMovementsReasons::Devolution->value)
            ->first();
        $this->assertNull($wasteDevolutionMovement);
    }

    public function test_partner_demands_cancellation_and_notification(): void
    {
        Notification::fake();

        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $requestService = $fixture['requestService'];
        $demandInProgress = $fixture['partnerDemand'];
        $partnerUser = $fixture['partnerUser'];
        $owner = $this->userWithRule(Rules::Owner);

        // Etapa 1 em andamento
        $demandInProgress->update(['started_at' => now()]);

        // Etapa 2 pendente (não iniciada)
        $step2 = ServiceStep::create(['name' => 'Acabamento']);
        $serviceServiceStep2 = ServiceServiceStep::create([
            'service_id' => $fixture['service']->id,
            'service_step_id' => $step2->id,
            'order' => 2,
        ]);
        $demandPending = PartnerDemand::create([
            'partner_id' => $fixture['partner']->id,
            'request_service_id' => $requestService->id,
            'service_service_step_id' => $serviceServiceStep2->id,
            'order' => 2,
        ]);

        // Etapa 3 já concluída (de outro serviço ou anterior)
        $step3 = ServiceStep::create(['name' => 'Fundição Concluída']);
        $serviceServiceStep3 = ServiceServiceStep::create([
            'service_id' => $fixture['service']->id,
            'service_step_id' => $step3->id,
            'order' => 3,
        ]);
        $demandDone = PartnerDemand::create([
            'partner_id' => $fixture['partner']->id,
            'request_service_id' => $requestService->id,
            'service_service_step_id' => $serviceServiceStep3->id,
            'order' => 3,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subHour(),
        ]);

        CancelDentistRequest::handle($order, [
            'category' => 'Moldagem / escaneamento inviável',
            'reason' => 'Arquivo STL corrompido e distorcido',
        ], $owner);

        $demandInProgress->refresh();
        $demandPending->refresh();
        $demandDone->refresh();

        $this->assertNotNull($demandInProgress->cancelled_at);
        $this->assertSame(DemandStatus::Cancelled, $demandInProgress->status);

        $this->assertNotNull($demandPending->cancelled_at);
        $this->assertSame(DemandStatus::Cancelled, $demandPending->status);

        // Demanda já concluída permanece como Done
        $this->assertNull($demandDone->cancelled_at);
        $this->assertSame(DemandStatus::Done, $demandDone->status);

        // Notificação de demanda interrompida enviada ao técnico da demanda em andamento
        Notification::assertSentTo(
            $partnerUser,
            PartnerDemandCancelledNotification::class,
            fn ($notification) => $notification->demand->id === $demandInProgress->id
        );

        // Notificação de cancelamento de pedido enviada ao dentista
        Notification::assertSentTo(
            $fixture['dentistUser'],
            OrderCancelledNotification::class,
            fn ($notification) => $notification->dentistRequest->id === $order->id
        );
    }

    public function test_cancelled_order_is_excluded_from_reports(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $owner = $this->userWithRule(Rules::Owner);

        // Ordem ativa aparece nos relatórios
        $response = Livewire::actingAs($owner)
            ->test('pages.reports.index');

        $this->assertSame(1, $response->get('profitabilitySummary')['orders_count']);
        $this->assertSame(1500000, $response->get('profitabilitySummary')['revenue_cents']);
        $this->assertSame(1, $response->get('revenueSummary')['orders_count']);
        $this->assertSame(1500000, $response->get('revenueSummary')['revenue_cents']);

        // Cancelar a ordem
        CancelDentistRequest::handle($order, [
            'category' => 'Erro de lançamento / duplicidade',
            'reason' => 'Cancelamento de teste',
        ], $owner);

        // Agora deve ser ignorada das consultas de receita e pedidos
        $responseAfter = Livewire::actingAs($owner)
            ->test('pages.reports.index');

        $this->assertSame(0, $responseAfter->get('profitabilitySummary')['orders_count']);
        $this->assertSame(0, $responseAfter->get('profitabilitySummary')['revenue_cents']);
        $this->assertSame(0, $responseAfter->get('revenueSummary')['orders_count']);
        $this->assertSame(0, $responseAfter->get('revenueSummary')['revenue_cents']);
    }

    public function test_livewire_modal_cancelling_flow_and_authorization(): void
    {
        Notification::fake();

        $fixture = $this->createOrderFixture();
        $order = $fixture['dentistRequest'];
        $owner = $this->userWithRule(Rules::Owner);
        $dentistUser = $fixture['dentistUser'];
        $partnerUser = $fixture['partnerUser'];

        // Parceiro não tem permissão para abrir modal
        Livewire::actingAs($partnerUser)
            ->test('service.cancel-order')
            ->call('open', $order->id)
            ->assertForbidden();

        // Equipe do laboratório abre modal e confirma cancelamento
        Livewire::actingAs($owner)
            ->test('service.cancel-order')
            ->call('open', $order->id)
            ->assertSet('requestId', $order->id)
            ->set('cancellationCategory', 'Erro de lançamento / duplicidade')
            ->set('cancellationReason', 'OS cancelada pelo modal de teste')
            ->call('confirmCancel')
            ->assertHasNoErrors()
            ->assertDispatched('requests::refresh');

        $order->refresh();
        $this->assertTrue($order->isCancelled());
        $this->assertSame('OS cancelada pelo modal de teste', $order->cancellation_reason);
    }
}
