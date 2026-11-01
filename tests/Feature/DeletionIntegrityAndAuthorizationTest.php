<?php

namespace Tests\Feature;

use App\Enums\Rules;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Enums\UnitMeasure;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\InvitationToken;
use App\Models\Partner;
use App\Models\PartnerDemand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\RequestService;
use App\Models\RequestServiceConsumption;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceStep;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DeletionIntegrityAndAuthorizationTest extends TestCase
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

    public function test_dentist_and_partner_cannot_delete_any_resource(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $partnerUser = $this->userWithRule(Rules::LabPartner);

        $dentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $partner = Partner::factory()->for($this->userWithRule(Rules::LabPartner))->create();
        $product = Product::create([
            'name' => 'Resina Teste',
            'unit_of_measure' => UnitMeasure::Unit,
            'min_stock' => 5,
        ]);
        $category = ProductCategory::create(['name' => 'Resinas']);
        $service = Service::create(['name' => 'Coroa Metal', 'price' => 100]);
        $step = ServiceStep::create(['name' => 'Fundição']);

        foreach ([$dentistUser, $partnerUser] as $unauthorizedUser) {
            Livewire::actingAs($unauthorizedUser)
                ->test('dentists.delete')
                ->set('dentistId', $dentist->id)
                ->call('delete')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('partners.delete')
                ->set('partner', $partner)
                ->call('delete')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.delete')
                ->set('product', $product)
                ->call('delete')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('products.categories.delete')
                ->set('category', $category)
                ->call('delete')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('service.delete')
                ->set('serviceId', $service->id)
                ->call('delete')
                ->assertForbidden();

            Livewire::actingAs($unauthorizedUser)
                ->test('step.delete')
                ->set('deleteStepId', $step->id)
                ->call('delete')
                ->assertForbidden();
        }
    }

    public function test_owner_lab_and_lab_manager_can_delete_resources(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $lab = $this->userWithRule(Rules::Lab);
        $manager = $this->userWithRule(Rules::LabManager);

        foreach ([$owner, $lab, $manager] as $authorizedUser) {
            $service = Service::create(['name' => 'Servico '.uniqid(), 'price' => 50]);
            Livewire::actingAs($authorizedUser)
                ->test('service.delete')
                ->set('serviceId', $service->id)
                ->call('delete')
                ->assertSuccessful();

            $this->assertSoftDeleted('services', ['id' => $service->id]);
        }
    }

    public function test_dentist_soft_delete_preserves_requests_demands_and_cleans_sessions(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();

        // Create active session and token
        DB::table('sessions')->insert([
            'id' => 'session-dentist-'.uniqid(),
            'user_id' => $dentistUser->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'dummy',
            'last_activity' => time(),
        ]);
        InvitationToken::create([
            'user_id' => $dentistUser->id,
            'token' => 'token-'.uniqid(),
            'expires_at' => now()->addDays(2),
        ]);

        $request = DentistRequest::factory()->for($dentist)->create();
        $service = Service::create(['name' => 'Prótese Fixa', 'price' => 300]);
        $reqService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 300,
            'quantity' => 1,
        ]);

        Livewire::actingAs($owner)
            ->test('dentists.delete')
            ->set('dentistId', $dentist->id)
            ->call('delete')
            ->assertSuccessful();

        $this->assertSoftDeleted('dentists', ['id' => $dentist->id]);
        $this->assertSoftDeleted('users', ['id' => $dentistUser->id]);

        // Integrity preserved
        $this->assertDatabaseHas('dentist_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('request_services', ['id' => $reqService->id]);

        // WithTrashed relation resolves properly
        $request->refresh();
        $this->assertNotNull($request->dentist);
        $this->assertSame($dentist->id, $request->dentist->id);
        $this->assertSame($dentistUser->id, $request->dentist->user->id);

        // Sessions and invitation tokens cleaned
        $this->assertDatabaseMissing('sessions', ['user_id' => $dentistUser->id]);
        $this->assertDatabaseMissing('invitation_tokens', ['user_id' => $dentistUser->id]);
    }

    public function test_partner_soft_delete_blocked_when_demands_in_progress(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $partner = Partner::factory()->for($partnerUser)->create();

        $dentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $request = DentistRequest::factory()->for($dentist)->create();
        $service = Service::create(['name' => 'Implante', 'price' => 500]);
        $step = ServiceStep::create(['name' => 'Usinagem']);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);
        $pivotId = DB::table('service_service_step')->where('service_id', $service->id)->first()->id;

        $reqService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 500,
            'quantity' => 1,
        ]);

        $demand = PartnerDemand::create([
            'request_service_id' => $reqService->id,
            'service_service_step_id' => $pivotId,
            'partner_id' => $partner->id,
            'order' => 1,
            'started_at' => now(),
            'ended_at' => null, // in progress!
        ]);

        Livewire::actingAs($owner)
            ->test('partners.delete')
            ->set('partner', $partner)
            ->call('delete')
            ->assertSuccessful();

        // Must NOT be deleted
        $this->assertDatabaseHas('partners', [
            'id' => $partner->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $partnerUser->id,
            'deleted_at' => null,
        ]);
    }

    public function test_partner_soft_delete_unassigns_pending_demands_and_preserves_completed(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $partner = Partner::factory()->for($partnerUser)->create();

        $dentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $request = DentistRequest::factory()->for($dentist)->create();
        $service = Service::create(['name' => 'Placa Miorrelaxante', 'price' => 200]);
        $step = ServiceStep::create(['name' => 'Termoformagem']);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);
        $partner->serviceSteps()->attach($step->id, ['commission' => 20, 'commission_type' => 'percentage']);
        $pivotId = DB::table('service_service_step')->where('service_id', $service->id)->first()->id;

        $reqService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 200,
            'quantity' => 1,
        ]);

        // Demand 1: Completed
        $completedDemand = PartnerDemand::create([
            'request_service_id' => $reqService->id,
            'service_service_step_id' => $pivotId,
            'partner_id' => $partner->id,
            'order' => 1,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
        ]);

        // Demand 2: Pending (not started)
        $pendingDemand = PartnerDemand::create([
            'request_service_id' => $reqService->id,
            'service_service_step_id' => $pivotId,
            'partner_id' => $partner->id,
            'order' => 2,
            'started_at' => null,
            'ended_at' => null,
        ]);

        Livewire::actingAs($owner)
            ->test('partners.delete')
            ->set('partner', $partner)
            ->call('delete')
            ->assertSuccessful();

        $this->assertSoftDeleted('partners', ['id' => $partner->id]);
        $this->assertSoftDeleted('users', ['id' => $partnerUser->id]);

        // Completed demand preserved with partner_id intact
        $completedDemand->refresh();
        $this->assertSame($partner->id, $completedDemand->partner_id);
        $this->assertNotNull($completedDemand->partner);

        // Pending demand unassigned
        $pendingDemand->refresh();
        $this->assertNull($pendingDemand->partner_id);

        // Step relation detached
        $this->assertDatabaseMissing('service_step_partner', ['partner_id' => $partner->id]);
    }

    public function test_service_and_product_soft_delete_preserves_historical_records(): void
    {
        $owner = $this->userWithRule(Rules::Owner);

        // Category & Product
        $category = ProductCategory::create(['name' => 'Gessos']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Gesso Pedra Tipo IV',
            'unit_of_measure' => UnitMeasure::Kilogram,
            'min_stock' => 10,
        ]);

        $batch = StockBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'LOTE-123',
            'initial_quantity' => 50,
            'current_quantity' => 50,
            'cost_price' => 35,
            'expiration_date' => now()->addYear(),
        ]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'stock_batch_id' => $batch->id,
            'type' => StockMovementsTypes::In,
            'reason' => StockMovementsReasons::Purchase,
            'quantity' => 50,
            'unit_cost' => 35,
        ]);

        $dentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $request = DentistRequest::factory()->for($dentist)->create();
        $service = Service::create(['name' => 'Modelo de Estudo', 'price' => 80]);
        $reqService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 80,
            'quantity' => 1,
        ]);

        $consumption = RequestServiceConsumption::create([
            'request_service_id' => $reqService->id,
            'product_id' => $product->id,
            'stock_batch_id' => $batch->id,
            'quantity_used' => 2,
        ]);

        // Delete product
        Livewire::actingAs($owner)
            ->test('products.delete')
            ->set('product', $product)
            ->call('delete')
            ->assertSuccessful();

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        // Delete service
        Livewire::actingAs($owner)
            ->test('service.delete')
            ->set('serviceId', $service->id)
            ->call('delete')
            ->assertSuccessful();

        $this->assertSoftDeleted('services', ['id' => $service->id]);

        // Relationships intact via withTrashed
        $batch->refresh();
        $this->assertSame('Gesso Pedra Tipo IV', $batch->product->name);

        $movement->refresh();
        $this->assertSame('Gesso Pedra Tipo IV', $movement->product->name);

        $consumption->refresh();
        $this->assertSame('Gesso Pedra Tipo IV', $consumption->product->name);

        $reqService->refresh();
        $this->assertSame('Modelo de Estudo', $reqService->service->name);
    }

    public function test_service_step_delete_blocked_if_active_service_attached(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $step = ServiceStep::create(['name' => 'Polimento']);
        $service = Service::create(['name' => 'Prótese Total', 'price' => 600]);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);

        Livewire::actingAs($owner)
            ->test('step.delete')
            ->set('deleteStepId', $step->id)
            ->call('delete')
            ->assertSuccessful();

        // Must not be deleted
        $this->assertDatabaseHas('service_steps', [
            'id' => $step->id,
            'deleted_at' => null,
        ]);

        // Detach service from step
        $service->serviceSteps()->detach($step->id);

        Livewire::actingAs($owner)
            ->test('step.delete')
            ->set('deleteStepId', $step->id)
            ->call('delete')
            ->assertSuccessful();

        $this->assertSoftDeleted('service_steps', ['id' => $step->id]);
    }

    public function test_product_category_delete_nullifies_product_category_id(): void
    {
        $owner = $this->userWithRule(Rules::Owner);
        $category = ProductCategory::create(['name' => 'Metais']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Liga Cobalto-Cromo',
            'unit_of_measure' => UnitMeasure::Gram,
            'min_stock' => 100,
        ]);

        Livewire::actingAs($owner)
            ->test('products.categories.delete')
            ->set('category', $category)
            ->call('delete')
            ->assertSuccessful();

        $this->assertSoftDeleted('product_categories', ['id' => $category->id]);

        $product->refresh();
        $this->assertNull($product->category_id);
    }

    public function test_can_recreate_records_with_same_name_or_email_after_soft_delete(): void
    {
        $owner = $this->userWithRule(Rules::Owner);

        // 1. Service name
        $service = Service::create(['name' => 'Facetas em Resina', 'price' => 250]);
        $step = ServiceStep::create(['name' => 'Modelagem']);

        // Validation fails when active service with same name exists
        Livewire::actingAs($owner)
            ->test('service.store')
            ->set('name', 'Facetas em Resina')
            ->set('description', 'Nova faceta')
            ->set('price', '300,00')
            ->set('steps', [['service_step_id' => $step->id, 'order' => 1]])
            ->call('store')
            ->assertHasErrors(['name']);

        // Soft delete the first service
        $service->delete();

        // Now can create new service with the same name
        Livewire::actingAs($owner)
            ->test('service.store')
            ->set('name', 'Facetas em Resina')
            ->set('description', 'Nova faceta recriada')
            ->set('price', '350,00')
            ->set('steps', [['service_step_id' => $step->id, 'order' => 1]])
            ->call('store')
            ->assertHasNoErrors();

        $this->assertSame(2, Service::withTrashed()->where('name', 'Facetas em Resina')->count());
        $this->assertSame(1, Service::where('name', 'Facetas em Resina')->count());

        // 2. ServiceStep name
        $step2 = ServiceStep::create(['name' => 'Escaneamento Intraoral', 'description' => 'Desc']);
        Livewire::actingAs($owner)
            ->test('step.store')
            ->set('name', 'Escaneamento Intraoral')
            ->set('description', 'Nova tentativa')
            ->call('store')
            ->assertHasErrors(['name']);

        $step2->delete();

        Livewire::actingAs($owner)
            ->test('step.store')
            ->set('name', 'Escaneamento Intraoral')
            ->set('description', 'Nova tentativa recriada')
            ->call('store')
            ->assertHasNoErrors();

        $this->assertSame(1, ServiceStep::where('name', 'Escaneamento Intraoral')->count());

        // 3. Product name
        $category = ProductCategory::create(['name' => 'Gerais']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Disco de PMMA',
            'unit_of_measure' => UnitMeasure::Unit,
            'min_stock' => 2,
        ]);

        Livewire::actingAs($owner)
            ->test('products.store')
            ->set('name', 'Disco de PMMA')
            ->set('categoryId', $category->id)
            ->set('unit_of_measure', UnitMeasure::Unit->value)
            ->set('minimumStock', 2)
            ->call('save')
            ->assertHasErrors(['name']);

        $product->delete();

        Livewire::actingAs($owner)
            ->test('products.store')
            ->set('name', 'Disco de PMMA')
            ->set('categoryId', $category->id)
            ->set('unit_of_measure', UnitMeasure::Unit->value)
            ->set('minimumStock', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Product::where('name', 'Disco de PMMA')->count());

        // 4. ProductCategory name
        $cat = ProductCategory::create(['name' => 'Fresas']);
        Livewire::actingAs($owner)
            ->test('products.categories.store')
            ->set('name', 'Fresas')
            ->call('save')
            ->assertHasErrors(['name']);

        $cat->delete();

        Livewire::actingAs($owner)
            ->test('products.categories.store')
            ->set('name', 'Fresas')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, ProductCategory::where('name', 'Fresas')->count());

        // 5. Dentist email
        $dentistUser = $this->userWithRule(Rules::Dentist, 'dr.silva@example.com');
        $dentist = Dentist::factory()->for($dentistUser)->create();

        Livewire::actingAs($owner)
            ->test('dentists.store')
            ->set('name', 'Dr. Silva 2')
            ->set('email', 'dr.silva@example.com')
            ->set('phone', '11999998888')
            ->call('save')
            ->assertHasErrors(['email']);

        $dentist->delete();
        $dentistUser->delete();

        Livewire::actingAs($owner)
            ->test('dentists.store')
            ->set('name', 'Dr. Silva Novo')
            ->set('email', 'dr.silva@example.com')
            ->set('phone', '11999998888')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, User::where('email', 'dr.silva@example.com')->count());

        // 6. Partner email
        $partnerUser = $this->userWithRule(Rules::LabPartner, 'parceiro.carlos@example.com');
        $partner = Partner::factory()->for($partnerUser)->create();

        Livewire::actingAs($owner)
            ->test('partners.store')
            ->set('name', 'Carlos Técnico')
            ->set('email', 'parceiro.carlos@example.com')
            ->set('phone', '11988887777')
            ->call('save')
            ->assertHasErrors(['email']);

        $partner->delete();
        $partnerUser->delete();

        Livewire::actingAs($owner)
            ->test('partners.store')
            ->set('name', 'Carlos Técnico Novo')
            ->set('email', 'parceiro.carlos@example.com')
            ->set('phone', '11988887777')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, User::where('email', 'parceiro.carlos@example.com')->count());
    }

    public function test_delete_user_form_blocks_only_lab_admin(): void
    {
        $labUser = $this->userWithRule(Rules::Lab);

        // Attempt deletion as the only lab admin
        Livewire::actingAs($labUser)
            ->test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertDatabaseHas('users', [
            'id' => $labUser->id,
            'deleted_at' => null,
        ]);

        // Create second lab admin
        $secondLabUser = $this->userWithRule(Rules::Lab);

        // Now first lab admin can delete their account
        Livewire::actingAs($labUser)
            ->test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $labUser->id]);
        $this->assertDatabaseHas('users', ['id' => $secondLabUser->id, 'deleted_at' => null]);
    }

    public function test_delete_user_form_blocks_partner_with_demands_in_progress(): void
    {
        $this->userWithRule(Rules::Lab); // ensure tenant has a lab admin
        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $partner = Partner::factory()->for($partnerUser)->create();

        $dentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $request = DentistRequest::factory()->for($dentist)->create();
        $service = Service::create(['name' => 'Prótese', 'price' => 100]);
        $step = ServiceStep::create(['name' => 'Fundição']);
        $service->serviceSteps()->attach($step->id, ['order' => 1]);
        $pivotId = DB::table('service_service_step')->where('service_id', $service->id)->first()->id;

        $reqService = RequestService::create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
            'unit_price' => 100,
            'quantity' => 1,
        ]);

        PartnerDemand::create([
            'request_service_id' => $reqService->id,
            'service_service_step_id' => $pivotId,
            'partner_id' => $partner->id,
            'order' => 1,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        Livewire::actingAs($partnerUser)
            ->test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertDatabaseHas('users', ['id' => $partnerUser->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('partners', ['id' => $partner->id, 'deleted_at' => null]);
    }

    public function test_delete_user_form_synchronizes_soft_delete_for_dentist_and_partner(): void
    {
        $this->userWithRule(Rules::Lab); // ensure tenant has a lab admin

        // Partner
        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $partner = Partner::factory()->for($partnerUser)->create();

        Livewire::actingAs($partnerUser)
            ->test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $partnerUser->id]);
        $this->assertSoftDeleted('partners', ['id' => $partner->id]);

        // Dentist
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();

        Livewire::actingAs($dentistUser)
            ->test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $dentistUser->id]);
        $this->assertSoftDeleted('dentists', ['id' => $dentist->id]);
    }
}
