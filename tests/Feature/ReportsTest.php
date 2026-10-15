<?php

namespace Tests\Feature;

use App\Actions\StockMovement as StockMovementAction;
use App\Enums\CommissionTypes;
use App\Enums\Rules;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Partner;
use App\Models\PartnerDemand;
use App\Models\Product;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceStep;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lab_user_sees_order_profitability_and_revenue_reports(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $dentist = $this->dentist();
        $service = Service::factory()->create(['name' => 'Coroa cerâmica']);
        $request = DentistRequest::factory()->for($dentist)->create();

        $requestService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 100,
            'quantity' => 2,
        ]);
        $this->createPartnerDemand(
            $requestService,
            $this->partner(),
            CommissionTypes::Percentage,
            10,
        );

        $product = Product::factory()->create([
            'current_stock' => 2,
            'has_batches' => false,
        ]);
        StockMovement::create([
            'dentist_request_id' => $request->id,
            'product_id' => $product->id,
            'type' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'quantity' => 2,
            'unit_cost' => 2.5,
        ]);

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk()
            ->assertSee('Lucratividade por pedido (CMV)')
            ->assertSee('Faturamento registrado')
            ->assertSee('R$ 200,00')
            ->assertSee('R$ 5,00')
            ->assertSee('R$ 20,00')
            ->assertSee('R$ 175,00')
            ->assertSee('87,5%');
    }

    public function test_profitability_report_always_includes_partner_commissions(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $dentist = $this->dentist();
        $service = Service::factory()->create();
        $request = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 100,
            'quantity' => 2,
        ]);
        $this->createPartnerDemand(
            $requestService,
            $this->partner(),
            CommissionTypes::Percentage,
            10,
        );

        $product = Product::factory()->create(['current_stock' => 2, 'has_batches' => false]);
        StockMovement::create([
            'dentist_request_id' => $request->id,
            'product_id' => $product->id,
            'type' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'quantity' => 2,
            'unit_cost' => 2.5,
        ]);

        Livewire::actingAs($user)
            ->test('pages.reports.index')
            ->assertSee('R$ 175,00')
            ->assertSee('87,5%')
            ->assertDontSee('Incluir comissão de parceiros no cálculo do lucro');
    }

    public function test_dentist_user_cannot_access_financial_reports(): void
    {
        $user = $this->userWithRule(Rules::Dentist);

        $this->actingAs($user)
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    public function test_service_consumption_movements_remain_linked_to_the_order(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $request = DentistRequest::factory()->for($this->dentist())->create();
        $product = Product::factory()->create([
            'current_stock' => 4,
            'has_batches' => false,
        ]);

        $this->actingAs($user);
        $movement = StockMovementAction::handle($product, [
            'dentist_request_id' => $request->id,
            'movementType' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'amount' => 1,
        ]);

        $this->assertSame($request->id, $movement->dentist_request_id);
    }

    public function test_service_consumption_from_a_batch_remains_linked_to_the_order(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $request = DentistRequest::factory()->for($this->dentist())->create();
        $product = Product::factory()->create([
            'current_stock' => 4,
            'has_batches' => true,
        ]);
        $batch = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'CMV-001',
            'initial_quantity' => 4,
            'current_quantity' => 4,
            'cost_price' => 2,
            'expiration_date' => now()->addYear(),
        ]);

        $this->actingAs($user);
        $movement = StockMovementAction::handle($product, [
            'dentist_request_id' => $request->id,
            'movementType' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'amount' => 1,
            'stockBatchId' => $batch->id,
        ]);

        $this->assertSame($request->id, $movement->dentist_request_id);
    }

    public function test_revenue_report_groups_amounts_by_dentist_and_service(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $dentistRule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);
        $dentistUser = User::factory()->for($dentistRule)->create(['name' => 'Dentista do relatório']);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $service = Service::factory()->create(['name' => 'Prótese de teste']);
        $request = DentistRequest::factory()->for($dentist)->create();

        RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 75,
            'quantity' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('pages.reports.index')
            ->set('activeReport', 'revenue')
            ->assertSee(now()->format('d/m/Y'))
            ->assertSee('R$ 150,00')
            ->set('revenueGroup', 'dentist')
            ->assertSee('Dentista do relatório')
            ->assertSee('R$ 150,00')
            ->set('revenueGroup', 'service')
            ->assertSee('Prótese de teste')
            ->assertSee('R$ 150,00');
    }

    public function test_partner_commission_report_aggregates_completed_demands_and_excludes_pending_demands(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $dentist = $this->dentist();
        $service = Service::factory()->create(['name' => 'Prótese de parceiro']);
        $request = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 75,
            'quantity' => 2,
        ]);
        $percentagePartner = $this->partner('Parceiro Percentual');
        $fixedPartner = $this->partner('Parceiro Fixo');

        $this->createPartnerDemand($requestService, $percentagePartner, CommissionTypes::Percentage, 10);
        $this->createPartnerDemand($requestService, $fixedPartner, CommissionTypes::Fixed, 12);
        $this->createPartnerDemand($requestService, $fixedPartner, CommissionTypes::Fixed, 99, completed: false);

        Livewire::actingAs($user)
            ->test('pages.reports.index')
            ->set('activeReport', 'partners')
            ->assertSee('Comissões de parceiros')
            ->assertSee('R$ 27,00')
            ->assertSee('Parceiro Percentual')
            ->assertSee('Parceiro Fixo')
            ->assertSee('demanda(s) de parceiro ainda não concluída(s)');
    }

    public function test_completed_partner_demand_without_commission_does_not_show_order_profit(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $dentist = $this->dentist();
        $service = Service::factory()->create();
        $request = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 100,
            'quantity' => 1,
        ]);
        $this->createPartnerDemand($requestService, $this->partner(), null, null);

        $product = Product::factory()->create(['current_stock' => 1, 'has_batches' => false]);
        StockMovement::create([
            'dentist_request_id' => $request->id,
            'product_id' => $product->id,
            'type' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'quantity' => 1,
            'unit_cost' => 2,
        ]);

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk()
            ->assertSee('Comissão incompleta');

        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $rows = (new \DOMXPath($document))->query('//tr[contains(normalize-space(string(.)), "Comissão incompleta")]');

        $this->assertNotFalse($rows);
        $this->assertSame(1, $rows->length);
        $profitCell = $rows->item(0)->getElementsByTagName('td')->item(5);

        $this->assertNotNull($profitCell);
        $this->assertSame('—', trim($profitCell->textContent));
    }

    public function test_unassigned_partner_step_keeps_order_profit_incomplete(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $dentist = $this->dentist();
        $service = Service::factory()->create();
        ServiceStep::factory()->create();
        $serviceStep = ServiceStep::factory()->create();
        $partner = $this->partner();
        $serviceStep->partners()->attach($partner->id, [
            'commission' => 10,
            'commission_type' => CommissionTypes::Percentage->value,
        ]);
        $service->serviceSteps()->attach($serviceStep->id, ['order' => 1]);

        $request = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 100,
            'quantity' => 1,
        ]);
        $demand = $requestService->partnerDemands()->sole();

        $this->assertSame($serviceStep->id, $demand->serviceServiceStep->service_step_id);

        $product = Product::factory()->create(['current_stock' => 1, 'has_batches' => false]);
        StockMovement::create([
            'dentist_request_id' => $request->id,
            'product_id' => $product->id,
            'type' => StockMovementsTypes::Out,
            'reason' => StockMovementsReasons::ServiceConsumption,
            'quantity' => 1,
            'unit_cost' => 2,
        ]);

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk()
            ->assertSee('Comissões pendentes');

        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $rows = (new \DOMXPath($document))->query('//tr[contains(normalize-space(string(.)), "Comissões pendentes")]');

        $this->assertNotFalse($rows);
        $this->assertSame(1, $rows->length);
        $profitCell = $rows->item(0)->getElementsByTagName('td')->item(5);

        $this->assertNotNull($profitCell);
        $this->assertSame('—', trim($profitCell->textContent));
    }

    public function test_invalid_date_range_does_not_break_report_rendering(): void
    {
        $user = $this->userWithRule(Rules::Lab);

        Livewire::actingAs($user)
            ->test('pages.reports.index')
            ->set('dateFrom', '2026-10-15')
            ->set('dateTo', '2026-10-14')
            ->call('applyFilters')
            ->assertHasErrors(['dateTo' => 'after_or_equal']);
    }

    public function test_orders_without_linked_material_cost_do_not_show_profit(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $dentist = $this->dentist();
        $service = Service::factory()->create();
        $request = DentistRequest::factory()->for($dentist)->create();

        RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 100,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk()
            ->assertSee('Sem consumo vinculado');

        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $rows = (new \DOMXPath($document))->query('//tr[contains(normalize-space(string(.)), "Sem consumo vinculado")]');

        $this->assertNotFalse($rows);
        $this->assertSame(1, $rows->length);
        $profitCell = $rows->item(0)->getElementsByTagName('td')->item(5);

        $this->assertNotNull($profitCell);
        $this->assertSame('—', trim($profitCell->textContent));
    }

    private function userWithRule(Rules $rule, ?string $name = null): User
    {
        $ruleModel = Rule::firstOrCreate(['name' => $rule->value]);

        return User::factory()
            ->for($ruleModel)
            ->create($name === null ? [] : ['name' => $name]);
    }

    private function dentist(): Dentist
    {
        $rule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);
        $user = User::factory()->for($rule)->create();

        return Dentist::factory()->for($user)->create();
    }

    private function partner(?string $name = null): Partner
    {
        return Partner::factory()
            ->for($this->userWithRule(Rules::LabPartner, $name))
            ->create();
    }

    private function createPartnerDemand(
        RequestService $requestService,
        Partner $partner,
        ?CommissionTypes $commissionType,
        ?int $commission,
        bool $completed = true,
    ): PartnerDemand {
        $serviceStep = ServiceStep::factory()->create();
        $serviceServiceStepId = DB::table('service_service_step')->insertGetId([
            'service_id' => $requestService->service_id,
            'service_step_id' => $serviceStep->id,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PartnerDemand::create([
            'request_service_id' => $requestService->id,
            'service_service_step_id' => $serviceServiceStepId,
            'partner_id' => $partner->id,
            'order' => 1,
            'partner_commission' => $commission,
            'partner_commission_type' => $commissionType?->value,
            'started_at' => now(),
            'ended_at' => $completed ? now() : null,
        ]);
    }
}
