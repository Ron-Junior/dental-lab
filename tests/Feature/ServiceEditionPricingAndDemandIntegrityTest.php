<?php

namespace Tests\Feature;

use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceServiceStep;
use App\Models\ServiceStep;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceEditionPricingAndDemandIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_existing_service_persists_name_description_price_and_steps(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $stepOne = ServiceStep::factory()->create(['name' => 'Escaneamento']);
        $stepTwo = ServiceStep::factory()->create(['name' => 'Fresagem']);

        $service = Service::create([
            'name' => 'Coroa Provisória',
            'description' => 'Descrição inicial',
            'price' => 250.00,
        ]);
        $service->serviceSteps()->attach($stepOne->id, ['order' => 1]);

        Livewire::actingAs($owner)
            ->test('service.store')
            ->call('edit', $service->id)
            ->assertSet('name', 'Coroa Provisória')
            ->assertSet('description', 'Descrição inicial')
            ->assertSet('price', '250,00')
            ->set('name', 'Coroa Zircônia Definitiva')
            ->set('description', 'Descrição atualizada com acabamento')
            ->set('price', '1.450,75')
            ->set('steps', [
                ['service_step_id' => $stepOne->id, 'order' => 1],
                ['service_step_id' => $stepTwo->id, 'order' => 2],
            ])
            ->call('store')
            ->assertHasNoErrors()
            ->assertDispatched('service::refresh');

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'name' => 'Coroa Zircônia Definitiva',
            'description' => 'Descrição atualizada com acabamento',
            'price' => 145075,
        ]);

        $service->refresh();
        $this->assertSame(1450.75, $service->price);
        $this->assertCount(2, $service->serviceSteps);
    }

    public function test_dentist_cannot_update_service_in_catalog(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $step = ServiceStep::factory()->create();

        $service = Service::create([
            'name' => 'Placa Miorrelaxante',
            'description' => 'Placa acrílica',
            'price' => 300.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        Livewire::actingAs($dentistUser)
            ->test('service.store')
            ->set('serviceId', $service->id)
            ->set('name', 'Tentativa Indevida')
            ->set('description', 'Alteração bloqueada')
            ->set('price', '100,00')
            ->set('steps', [
                ['service_step_id' => $step->id, 'order' => 1],
            ])
            ->call('store')
            ->assertForbidden();

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'name' => 'Placa Miorrelaxante',
            'price' => 30000,
        ]);
    }

    public function test_removing_service_step_with_existing_partner_demands_is_rejected_and_preserves_history(): void
    {
        $labManager = $this->userWithRule(Rules::LabManager);
        $dentist = $this->dentistWithUser();

        $stepOne = ServiceStep::factory()->create(['name' => 'Enceramento']);
        $stepTwo = ServiceStep::factory()->create(['name' => 'Fundição']);

        $service = Service::create([
            'name' => 'Prótese Fixa',
            'description' => 'Prótese metálica',
            'price' => 800.00,
        ]);
        $service->serviceSteps()->attach([
            $stepOne->id => ['order' => 1],
            $stepTwo->id => ['order' => 2],
        ]);

        $dentistRequest = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 800.00,
            'quantity' => 1,
        ]);

        $this->assertSame(2, $requestService->partnerDemands()->count());

        // Attempt to remove $stepTwo ('Fundição') which already has PartnerDemand records
        Livewire::actingAs($labManager)
            ->test('service.store')
            ->call('edit', $service->id)
            ->set('name', 'Prótese Fixa Editada')
            ->set('steps', [
                ['service_step_id' => $stepOne->id, 'order' => 1],
            ])
            ->call('store')
            ->assertHasErrors(['steps']);

        // Ensure historical demands and pivot were NOT deleted
        $this->assertSame(2, $requestService->partnerDemands()->count());
        $this->assertSame(2, $service->serviceSteps()->count());

        // Also verify database-level restrictOnDelete constraint prevents raw pivot deletion
        $pivotWithDemand = ServiceServiceStep::where('service_id', $service->id)
            ->where('service_step_id', $stepTwo->id)
            ->firstOrFail();

        $this->expectException(QueryException::class);
        $pivotWithDemand->delete();
    }

    public function test_request_service_with_started_demands_cannot_be_edited_or_have_service_changed(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();

        $stepOne = ServiceStep::factory()->create(['name' => 'Modelagem']);
        $stepTwo = ServiceStep::factory()->create(['name' => 'Acabamento']);

        $serviceA = Service::create([
            'name' => 'Faceta Resina',
            'description' => 'Faceta direta',
            'price' => 400.00,
        ]);
        $serviceA->serviceSteps()->attach($stepOne->id, ['order' => 1]);

        $serviceB = Service::create([
            'name' => 'Lente Porcelana',
            'description' => 'Lente cerâmica',
            'price' => 1200.00,
        ]);
        $serviceB->serviceSteps()->attach($stepTwo->id, ['order' => 1]);

        $dentistRequest = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $serviceA->id,
            'unit_price' => 400.00,
            'quantity' => 2,
        ]);

        $demand = $requestService->partnerDemands()->firstOrFail();
        $demand->update(['started_at' => now()]);

        Livewire::actingAs($owner)
            ->test('service.update')
            ->call('open', $requestService->id)
            ->set('serviceId', $serviceB->id)
            ->set('quantity', 5)
            ->call('save')
            ->assertHasErrors(['serviceId']);

        $requestService->refresh();
        $this->assertSame($serviceA->id, $requestService->service_id);
        $this->assertSame(2, $requestService->quantity);
        $this->assertSame(1, $requestService->partnerDemands()->count());
        $this->assertSame($demand->id, $requestService->partnerDemands()->first()->id);

        // Verify planned view renders the tooltip explaining why editing is blocked
        Livewire::actingAs($owner)
            ->test('pages.services.planned')
            ->assertSee('Não é possível editar este serviço pois as etapas já foram iniciadas pelos técnicos parceiros');
    }

    public function test_request_service_without_started_demands_can_switch_service_and_syncs_new_partner_demands(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();

        $stepA = ServiceStep::factory()->create(['name' => 'Etapa A']);
        $stepB1 = ServiceStep::factory()->create(['name' => 'Etapa B1']);
        $stepB2 = ServiceStep::factory()->create(['name' => 'Etapa B2']);

        $serviceA = Service::create([
            'name' => 'Serviço Inicial',
            'description' => 'Uma etapa',
            'price' => 300.00,
        ]);
        $serviceA->serviceSteps()->attach($stepA->id, ['order' => 1]);

        $serviceB = Service::create([
            'name' => 'Serviço Substituto',
            'description' => 'Duas etapas',
            'price' => 1500.00,
        ]);
        $serviceB->serviceSteps()->attach([
            $stepB1->id => ['order' => 1],
            $stepB2->id => ['order' => 2],
        ]);

        $dentistRequest = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $serviceA->id,
            'unit_price' => 300.00,
            'quantity' => 1,
        ]);

        $oldDemandIds = $requestService->partnerDemands()->pluck('id')->all();
        $this->assertCount(1, $oldDemandIds);

        Livewire::actingAs($owner)
            ->test('service.update')
            ->call('open', $requestService->id)
            ->set('serviceId', $serviceB->id)
            ->set('quantity', 3)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('requests::refresh');

        $requestService->refresh();
        $this->assertSame($serviceB->id, $requestService->service_id);
        $this->assertEquals(1500.00, $requestService->unit_price);
        $this->assertDatabaseHas('request_services', [
            'id' => $requestService->id,
            'service_id' => $serviceB->id,
            'unit_price' => 150000,
            'quantity' => 3,
        ]);
        $this->assertSame(3, $requestService->quantity);

        foreach ($oldDemandIds as $oldDemandId) {
            $this->assertDatabaseMissing('partner_demands', ['id' => $oldDemandId]);
        }

        $newDemands = $requestService->partnerDemands()->orderBy('order')->get();
        $this->assertCount(2, $newDemands);

        $expectedPivotIds = ServiceServiceStep::where('service_id', $serviceB->id)
            ->orderBy('order')
            ->pluck('id')
            ->all();

        $this->assertSame($expectedPivotIds, $newDemands->pluck('service_service_step_id')->all());
    }

    public function test_currency_conversion_handles_thousand_and_million_formatted_values_accurately(): void
    {
        $this->assertSame(999.99, currency_to_float('R$ 999,99'));
        $this->assertSame(1500.00, currency_to_float('R$ 1.500,00'));
        $this->assertSame(1250000.50, currency_to_float('R$ 1.250.000,50'));
        $this->assertSame(99999, currency_to_int('R$ 999,99'));
        $this->assertSame(150000, currency_to_int('R$ 1.500,00'));
        $this->assertSame(125000050, currency_to_int('R$ 1.250.000,50'));

        $owner = $this->userWithRule(Rules::Owner);
        $step = ServiceStep::factory()->create();

        foreach ([
            ['label' => 'Serviço 999', 'formatted' => 'R$ 999,99', 'cents' => 99999],
            ['label' => 'Serviço 1500', 'formatted' => 'R$ 1.500,00', 'cents' => 150000],
            ['label' => 'Serviço Milhão', 'formatted' => 'R$ 1.250.000,50', 'cents' => 125000050],
        ] as $case) {
            Livewire::actingAs($owner)
                ->test('service.store')
                ->set('name', $case['label'])
                ->set('description', 'Teste de precisão monetária')
                ->set('price', $case['formatted'])
                ->set('steps', [
                    ['service_step_id' => $step->id, 'order' => 1],
                ])
                ->call('store')
                ->assertHasNoErrors();

            $this->assertDatabaseHas('services', [
                'name' => $case['label'],
                'price' => $case['cents'],
            ]);
        }
    }

    public function test_high_volume_quantity_above_smallint_limit_persists_in_request_services(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();
        $step = ServiceStep::factory()->create();

        $service = Service::create([
            'name' => 'Bráquete Cerâmico em Lote',
            'description' => 'Fornecimento em alta tiragem',
            'price' => 1250000.50,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        Livewire::actingAs($owner)
            ->test('service.requesting')
            ->set('dentistId', $dentist->id)
            ->set('services', [
                [
                    'service_id' => $service->id,
                    'unit_price' => '1.250.000,50',
                    'quantity' => 50000,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $requestService = RequestService::firstOrFail();
        $this->assertDatabaseHas('request_services', [
            'id' => $requestService->id,
            'unit_price' => 125000050,
            'quantity' => 50000,
        ]);

        Livewire::actingAs($owner)
            ->test('service.update')
            ->call('open', $requestService->id)
            ->set('quantity', 100000)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('request_services', [
            'id' => $requestService->id,
            'quantity' => 100000,
        ]);
    }

    public function test_dentist_cannot_tamper_with_unit_price_in_service_requesting(): void
    {
        $dentist = $this->dentistWithUser();
        $dentistUser = $dentist->user;
        $step = ServiceStep::factory()->create();

        $service = Service::create([
            'name' => 'Coroa E-Max',
            'description' => 'Coroa cerâmica pura',
            'price' => 450.00,
        ]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        foreach (['0,01', '0,00'] as $tamperedPrice) {
            Livewire::actingAs($dentistUser)
                ->test('service.requesting')
                ->set('services', [
                    [
                        'service_id' => $service->id,
                        'unit_price' => $tamperedPrice,
                        'quantity' => 2,
                    ],
                ])
                ->call('save')
                ->assertHasNoErrors();
        }

        $requestServices = RequestService::where('service_id', $service->id)->get();
        $this->assertCount(2, $requestServices);

        foreach ($requestServices as $requestService) {
            $this->assertEquals(450.00, $requestService->unit_price);
            $this->assertDatabaseHas('request_services', [
                'id' => $requestService->id,
                'service_id' => $service->id,
                'unit_price' => 45000,
                'quantity' => 2,
            ]);
        }
    }

    public function test_multiple_services_in_requesting_ignore_tampered_or_empty_unit_prices_across_roles(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();
        $step = ServiceStep::factory()->create();

        $serviceA = Service::create([
            'name' => 'Inlay Cerâmico',
            'description' => 'Inlay estético',
            'price' => 380.50,
        ]);
        $serviceA->serviceSteps()->attach($step->id, ['order' => 1]);

        $serviceB = Service::create([
            'name' => 'Protocolo Acrílico',
            'description' => 'Prótese sobre implante',
            'price' => 1850.00,
        ]);
        $serviceB->serviceSteps()->attach($step->id, ['order' => 1]);

        Livewire::actingAs($owner)
            ->test('service.requesting')
            ->set('dentistId', $dentist->id)
            ->set('services', [
                [
                    'service_id' => $serviceA->id,
                    'unit_price' => '0,01',
                    'quantity' => 1,
                ],
                [
                    'service_id' => $serviceB->id,
                    'unit_price' => '',
                    'quantity' => 3,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('request_services', [
            'service_id' => $serviceA->id,
            'unit_price' => 38050,
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('request_services', [
            'service_id' => $serviceB->id,
            'unit_price' => 185000,
            'quantity' => 3,
        ]);
    }

    public function test_service_update_ignores_tampered_unit_price_and_preserves_historical_or_new_catalog_price(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentist = $this->dentistWithUser();
        $step = ServiceStep::factory()->create();

        $serviceA = Service::create([
            'name' => 'Provisório Fresado',
            'description' => 'PMMA',
            'price' => 200.00,
        ]);
        $serviceA->serviceSteps()->attach($step->id, ['order' => 1]);

        $serviceB = Service::create([
            'name' => 'Zircônia Translúcida',
            'description' => 'Monolítica',
            'price' => 650.00,
        ]);
        $serviceB->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::factory()->for($dentist)->create();
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $serviceA->id,
            'unit_price' => 200.00,
            'quantity' => 1,
        ]);

        // Catalog price changes afterwards; historical price on $requestService is still 200.00 (20000 cents)
        $serviceA->update(['price' => 290.00]);

        // Case 1: Only quantity changed, unitPrice tampered to '0,01' -> preserves historical price (20000 cents)
        Livewire::actingAs($owner)
            ->test('service.update')
            ->call('open', $requestService->id)
            ->set('unitPrice', '0,01')
            ->set('quantity', 4)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('request_services', [
            'id' => $requestService->id,
            'service_id' => $serviceA->id,
            'unit_price' => 20000,
            'quantity' => 4,
        ]);

        // Case 2: Service switched to $serviceB, unitPrice tampered to '0,00' -> uses official catalog price of $serviceB (65000 cents)
        Livewire::actingAs($owner)
            ->test('service.update')
            ->call('open', $requestService->id)
            ->set('serviceId', $serviceB->id)
            ->set('unitPrice', '0,00')
            ->set('quantity', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('request_services', [
            'id' => $requestService->id,
            'service_id' => $serviceB->id,
            'unit_price' => 65000,
            'quantity' => 2,
        ]);
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
