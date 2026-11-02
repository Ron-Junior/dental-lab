<?php

namespace Tests\Feature;

use App\Enums\Rules;
use App\Enums\UnitMeasure;
use App\Models\CentralUser;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LivewireServerSideAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = true;

    private function userWithRule(Rules $rule, ?string $email = null): User
    {
        $ruleModel = Rule::firstOrCreate(['name' => $rule->value]);

        return User::factory()->for($ruleModel)->create(array_filter([
            'email' => $email,
            'password' => Hash::make('password'),
        ]));
    }

    public function test_dentist_and_partner_cannot_open_or_complete_service_progress(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $partnerUser = $this->userWithRule(Rules::LabPartner);

        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $service = Service::create(['name' => 'Prótese Teste', 'price' => 150]);
        $step = ServiceStep::create(['name' => 'Etapa 1']);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 150,
            'quantity' => 1,
        ]);

        foreach ([$dentistUser, $partnerUser] as $unauthorizedUser) {
            Livewire::actingAs($unauthorizedUser)
                ->test('service.progress')
                ->call('open', $requestService->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('service.progress')
                ->call('complete')
                ->assertForbidden();

            $requestService->refresh();
            $this->assertNull($requestService->completed_at);
        }
    }

    public function test_owner_lab_and_lab_manager_can_open_and_complete_service_progress(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $lab = $this->userWithRule(Rules::Lab);
        $manager = $this->userWithRule(Rules::LabManager);

        $dentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $service = Service::create(['name' => 'Coroa Cerâmica', 'price' => 200]);
        $step = ServiceStep::create(['name' => 'Acabamento']);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        foreach ([$owner, $lab, $manager] as $authorizedUser) {
            $reqService = RequestService::create([
                'dentist_request_id' => $dentistRequest->id,
                'service_id' => $service->id,
                'unit_price' => 200,
                'quantity' => 1,
            ]);

            // Finaliza as etapas pendentes para permitir conclusão
            foreach ($reqService->partnerDemands as $demand) {
                $demand->update(['started_at' => now(), 'ended_at' => now()]);
            }

            Livewire::actingAs($authorizedUser)
                ->test('service.progress')
                ->call('open', $reqService->id)
                ->assertSuccessful()
                ->call('complete')
                ->assertSuccessful();

            $reqService->refresh();
            $this->assertNotNull($reqService->completed_at);
        }
    }

    public function test_dentist_can_load_and_download_own_request_files_but_forbidden_on_others(): void
    {
        Storage::fake('s3');

        $dentistUserA = $this->userWithRule(Rules::Dentist);
        $dentistA = Dentist::factory()->for($dentistUserA)->create();
        $requestA = DentistRequest::create(['dentist_id' => $dentistA->id]);
        $fileA = $requestA->files()->create([
            'name' => 'scan_a.zip',
            'path' => 'requests/scan_a.zip',
            'disk' => 's3',
            'mime_type' => 'application/zip',
            'extension' => 'zip',
            'size' => 1024,
            'original_size' => 1024,
        ]);
        Storage::disk('s3')->put('requests/scan_a.zip', 'content-a');

        $dentistUserB = $this->userWithRule(Rules::Dentist);
        $dentistB = Dentist::factory()->for($dentistUserB)->create();
        $requestB = DentistRequest::create(['dentist_id' => $dentistB->id]);
        $fileB = $requestB->files()->create([
            'name' => 'scan_b.zip',
            'path' => 'requests/scan_b.zip',
            'disk' => 's3',
            'mime_type' => 'application/zip',
            'extension' => 'zip',
            'size' => 2048,
            'original_size' => 2048,
        ]);
        Storage::disk('s3')->put('requests/scan_b.zip', 'content-b');

        $partnerUser = $this->userWithRule(Rules::LabPartner);

        // Dentista A acessa seus próprios arquivos
        Livewire::actingAs($dentistUserA)
            ->test('service.request-files')
            ->call('loadRequest', $requestA->id)
            ->assertSuccessful()
            ->call('download', $fileA->id)
            ->assertRedirect();

        // Dentista A tenta acessar arquivos de B -> 403
        $componentA = Livewire::actingAs($dentistUserA)
            ->test('service.request-files')
            ->call('loadRequest', $requestB->id)
            ->assertForbidden();
        $this->assertNull($componentA->get('dentistRequest'));

        // Parceiro tenta acessar arquivos de A -> 403
        Livewire::actingAs($partnerUser)
            ->test('service.request-files')
            ->call('loadRequest', $requestA->id)
            ->assertForbidden();

        // Lab admin acessa arquivos de A normalmente
        $owner = $this->userWithRule(Rules::Owner);
        Livewire::actingAs($owner)
            ->test('service.request-files')
            ->call('loadRequest', $requestA->id)
            ->assertSuccessful()
            ->call('download', $fileA->id)
            ->assertRedirect();
    }

    public function test_assign_demand_authorization_and_partner_validation(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();

        $partnerUser1 = $this->userWithRule(Rules::LabPartner);
        $partner1 = Partner::factory()->for($partnerUser1)->create();

        $partnerUser2 = $this->userWithRule(Rules::LabPartner);
        $partner2 = Partner::factory()->for($partnerUser2)->create();

        $service = Service::create(['name' => 'Serviço Demandas', 'price' => 100]);
        $step = ServiceStep::create(['name' => 'Etapa Demandas']);
        // Apenas parceiro 1 está vinculado à etapa
        $step->partners()->attach($partner1->id, ['commission' => 10, 'commission_type' => 'percentage']);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 100,
            'quantity' => 1,
        ]);
        $demand = $requestService->partnerDemands()->first();

        // 1. Dentista não pode abrir modal nem atribuir
        Livewire::actingAs($dentistUser)
            ->test('partners.assign-demand')
            ->call('openModal', $demand->id)
            ->assertForbidden();

        Livewire::actingAs($dentistUser)
            ->test('partners.assign-demand')
            ->call('assignDemand')
            ->assertForbidden();

        // 2. Parceiro 2 (não vinculado à etapa) não pode abrir modal nem atribuir
        Livewire::actingAs($partnerUser2)
            ->test('partners.assign-demand')
            ->call('openModal', $demand->id)
            ->assertForbidden();

        // 3. Parceiro 1 (vinculado à etapa) pode abrir modal
        Livewire::actingAs($partnerUser1)
            ->test('partners.assign-demand')
            ->call('openModal', $demand->id)
            ->assertSuccessful();

        // 4. Tentar atribuir para parceiro 2 (não vinculado à etapa) falha validação
        Livewire::actingAs($owner)
            ->test('partners.assign-demand')
            ->call('openModal', $demand->id)
            ->assertSuccessful()
            ->set('partnerId', $partner2->id)
            ->call('assignDemand')
            ->assertHasErrors(['partnerId']);

        // 5. Atribuir para parceiro 1 funciona
        Livewire::actingAs($owner)
            ->test('partners.assign-demand')
            ->call('openModal', $demand->id)
            ->set('partnerId', $partner1->id)
            ->call('assignDemand')
            ->assertHasNoErrors();

        $demand->refresh();
        $this->assertSame($partner1->id, $demand->partner_id);
    }

    public function test_partner_and_dentist_cannot_move_or_withdraw_stock(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $ownerUser = $this->userWithRule(Rules::Owner);

        $category = ProductCategory::create(['name' => 'Insumos']);
        $product = Product::create([
            'name' => 'Silicone de Condensação',
            'unit_of_measure' => UnitMeasure::Unit,
            'min_stock' => 5,
            'current_stock' => 50,
            'category_id' => $category->id,
            'has_batches' => false,
        ]);

        $dentist = Dentist::factory()->for($dentistUser)->create();
        $request = DentistRequest::create(['dentist_id' => $dentist->id]);
        $service = Service::create(['name' => 'Serviço Insumo', 'price' => 80]);
        $reqService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 80,
            'quantity' => 1,
        ]);

        foreach ([$dentistUser, $partnerUser] as $unauthorizedUser) {
            // products.stock.out
            Livewire::actingAs($unauthorizedUser)
                ->test('products.stock.out')
                ->call('open', $reqService->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.stock.out')
                ->call('save')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.stock.out')
                ->call('registerOut')
                ->assertForbidden();

            // products.stock.store
            Livewire::actingAs($unauthorizedUser)
                ->test('products.stock.store')
                ->call('openForProduct', $product->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.stock.store')
                ->call('save')
                ->assertForbidden();

            // stock.get
            Livewire::actingAs($unauthorizedUser)
                ->test('stock.get')
                ->call('open', $reqService->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('stock.get')
                ->call('callStockOut')
                ->assertForbidden();
        }

        $product->refresh();
        $this->assertEquals(50, (float) $product->current_stock);

        // Lab admin tem acesso autorizado
        Livewire::actingAs($ownerUser)
            ->test('products.stock.out')
            ->call('open', $reqService->id)
            ->assertSuccessful();

        Livewire::actingAs($ownerUser)
            ->test('products.stock.store')
            ->call('openForProduct', $product->id)
            ->assertSuccessful();

        Livewire::actingAs($ownerUser)
            ->test('stock.get')
            ->call('open', $reqService->id)
            ->assertSuccessful();
    }

    public function test_dentist_and_partner_cannot_execute_administrative_crud_actions(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $owner = $this->userWithRule(Rules::Owner);

        $targetDentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $targetPartner = Partner::factory()->for($this->userWithRule(Rules::LabPartner))->create();
        $service = Service::create(['name' => 'Serviço CRUD', 'price' => 120]);
        $step = ServiceStep::create(['name' => 'Etapa CRUD']);
        $category = ProductCategory::create(['name' => 'Categoria CRUD']);
        $product = Product::create([
            'name' => 'Produto CRUD',
            'unit_of_measure' => UnitMeasure::Unit,
            'min_stock' => 1,
            'category_id' => $category->id,
            'has_batches' => false,
        ]);

        foreach ([$dentistUser, $partnerUser] as $unauthorizedUser) {
            // Dentists CRUD
            Livewire::actingAs($unauthorizedUser)
                ->test('dentists.store')
                ->call('edit', $targetDentist->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('dentists.store')
                ->call('save')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('dentists.delete')
                ->call('confirmDelete', $targetDentist->id)
                ->assertForbidden();

            // Partners CRUD
            Livewire::actingAs($unauthorizedUser)
                ->test('partners.store')
                ->call('edit', $targetPartner->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('partners.store')
                ->call('save')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('partners.delete')
                ->call('confirmDelete', $targetPartner->id)
                ->assertForbidden();

            // Service CRUD
            Livewire::actingAs($unauthorizedUser)
                ->test('service.store')
                ->call('edit', $service->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('service.delete')
                ->call('confirmDelete', $service->id)
                ->assertForbidden();

            // Service Step CRUD
            Livewire::actingAs($unauthorizedUser)
                ->test('step.store')
                ->call('edit', $step->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('step.store')
                ->call('store')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('step.delete')
                ->call('open', $step->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('step.manage-partners')
                ->call('open', $step->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('step.manage-partners')
                ->call('save')
                ->assertForbidden();

            // Products & Categories CRUD
            Livewire::actingAs($unauthorizedUser)
                ->test('products.store')
                ->call('edit', $product->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.store')
                ->call('save')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.delete')
                ->call('confirmDelete', $product->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.categories.store')
                ->call('edit', $category->id)
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.categories.store')
                ->call('save')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.categories.delete')
                ->call('open', $category->id)
                ->assertForbidden();
        }

        // Owner tem permissão completa de edição/abertura
        Livewire::actingAs($owner)
            ->test('dentists.store')
            ->call('edit', $targetDentist->id)
            ->assertSuccessful();

        Livewire::actingAs($owner)
            ->test('partners.store')
            ->call('edit', $targetPartner->id)
            ->assertSuccessful();

        Livewire::actingAs($owner)
            ->test('service.store')
            ->call('edit', $service->id)
            ->assertSuccessful();

        Livewire::actingAs($owner)
            ->test('step.store')
            ->call('edit', $step->id)
            ->assertSuccessful();

        Livewire::actingAs($owner)
            ->test('products.store')
            ->call('edit', $product->id)
            ->assertSuccessful();

        Livewire::actingAs($owner)
            ->test('products.categories.store')
            ->call('edit', $category->id)
            ->assertSuccessful();
    }

    public function test_central_plans_actions_require_central_user_guard(): void
    {
        $tenantUser = $this->userWithRule(Rules::Lab);

        tenancy()->end();

        $plan = Plan::query()->firstOrCreate(
            ['name' => 'Plano Para Teste Central'],
            [
                'price_in_cents' => 9900,
                'billing_cycle' => 'monthly',
                'is_active' => true,
            ]
        );

        // 1. Sem autenticação no guard central -> 403 Forbidden ao acessar o componente
        Volt::test('pages.central.plans.index')
            ->assertForbidden();

        // 2. Com usuário autenticado apenas no guard padrão (tenant/web) -> 403 Forbidden
        Volt::actingAs($tenantUser)
            ->test('pages.central.plans.index')
            ->assertForbidden();

        // 3. Com CentralUser autenticado no guard central -> sucesso
        $centralUser = $this->createCentralUser();
        $this->actingAs($centralUser, 'central');

        Volt::test('pages.central.plans.index')
            ->assertSuccessful()
            ->call('openCreatePlanModal')
            ->assertSuccessful()
            ->call('openEditPlanModal', (string) $plan->id)
            ->assertSuccessful();
    }

    private function createCentralUser(): CentralUser
    {
        return CentralUser::query()->create([
            'name' => 'Sócio Admin',
            'email' => 'socio@protho.test',
            'password' => 'safe-password',
        ]);
    }
}
