<?php

namespace Tests\Feature;

use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Partner;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PagePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lab_staff_can_access_operational_pages(): void
    {
        $this->actingAs($this->userWithRule(Rules::LabManager));

        foreach ($this->operationalPageUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_owner_and_lab_roles_share_management_page_access(): void
    {
        foreach ([Rules::Owner, Rules::Lab] as $rule) {
            $this->actingAs($this->userWithRule($rule));

            foreach ([
                route('products.index'),
                route('dentists.demands.index'),
                route('services.planned.index'),
                route('reports.index'),
            ] as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_lab_partners_can_access_demands_but_not_other_restricted_pages(): void
    {
        $this->actingAs($this->userWithRule(Rules::LabPartner));

        $this->get(route('dentists.demands.index'))->assertOk();
        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('services.planned.index'))->assertForbidden();
        $this->get(route('reports.index'))->assertForbidden();
    }

    public function test_dentists_can_access_requests_but_not_lab_management_or_demands(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUser)->create();
        $this->actingAs($dentistUser);

        $this->get(route('services.planned.index'))->assertOk();
        $this->get(route('dentists.demands.index'))->assertForbidden();
        $this->get(route('products.index'))->assertForbidden();
        $this->get(route('reports.index'))->assertForbidden();
    }

    public function test_dentists_only_see_their_own_service_requests(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $otherDentist = $this->dentistWithRule(Rules::Dentist);
        $service = Service::factory()->create();
        $serviceStep = ServiceStep::factory()->create();
        $service->serviceSteps()->attach($serviceStep->id, ['order' => 1]);

        $this->createRequest($dentistUser, $service, 'own-request');
        $this->createRequest($otherDentist->user, $service, 'other-request');

        Livewire::actingAs($dentistUser)
            ->test('pages.services.planned')
            ->assertSee('own-request')
            ->assertDontSee('other-request');
    }

    public function test_dentist_request_creation_uses_the_authenticated_dentist(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $otherDentist = $this->dentistWithRule(Rules::Dentist);
        $service = Service::factory()->create();

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->set('dentistId', $otherDentist->id)
            ->set('services.0.service_id', $service->id)
            ->set('services.0.quantity', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dentist_requests', ['dentist_id' => $dentist->id]);
        $this->assertDatabaseMissing('dentist_requests', ['dentist_id' => $otherDentist->id]);
    }

    public function test_lab_partners_only_see_demands_assigned_to_their_account(): void
    {
        $partnerUser = $this->userWithRule(Rules::LabPartner);
        $otherPartner = Partner::factory()->create();
        $partner = Partner::factory()->for($partnerUser)->create();
        $service = Service::factory()->create();
        $serviceStep = ServiceStep::factory()->create();
        $service->serviceSteps()->attach($serviceStep->id, ['order' => 1]);

        $ownRequestService = $this->createRequest(
            $this->userWithRule(Rules::Dentist),
            $service,
            'own-demand-request',
        );
        $otherRequestService = $this->createRequest(
            $this->userWithRule(Rules::Dentist),
            $service,
            'other-demand-request',
        );
        $ownDemand = $ownRequestService->partnerDemands()->firstOrFail();
        $ownDemand->update(['partner_id' => $partner->id]);
        $otherRequestService->partnerDemands()->firstOrFail()->update(['partner_id' => $otherPartner->id]);

        $this->assertTrue(Gate::forUser($partnerUser)->allows('start', $ownDemand));
        $ownDemand->update(['started_at' => now()]);
        $ownDemand->refresh();
        $this->assertTrue(Gate::forUser($partnerUser)->allows('complete', $ownDemand));

        Livewire::actingAs($partnerUser)
            ->test('pages.dentists.demands')
            ->assertSee('#own-dem')
            ->assertDontSee('#other-d');
    }

    public function test_dentists_cannot_update_request_services(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentistRequest = DentistRequest::factory()
            ->for(Dentist::factory()->for($dentistUser))
            ->create();
        $requestService = RequestService::factory()
            ->for($dentistRequest)
            ->create();

        $this->assertFalse(Gate::forUser($dentistUser)->allows('update', $requestService));
    }

    private function operationalPageUrls(): array
    {
        return [
            route('steps.index'),
            route('products.index'),
            route('products.categories.index'),
            route('products.movements.index'),
            route('partners.index'),
            route('dentists.index'),
            route('dentists.demands.index'),
            route('services.index'),
            route('services.planned.index'),
            route('dentist-request.show', ['id' => 1]),
            route('reports.index'),
        ];
    }

    private function userWithRule(Rules $rule): User
    {
        $ruleModel = Rule::firstOrCreate(['name' => $rule->value]);

        return User::factory()->for($ruleModel)->create();
    }

    private function dentistWithRule(Rules $rule): Dentist
    {
        return Dentist::factory()
            ->for($this->userWithRule($rule))
            ->create();
    }

    private function createRequest(User $dentistUser, Service $service, string $code): RequestService
    {
        $dentistRequest = DentistRequest::factory()
            ->for(Dentist::factory()->for($dentistUser))
            ->create(['code' => $code]);

        return RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 10,
            'quantity' => 1,
        ]);
    }
}
