<?php

namespace Tests\Feature;

use App\Enums\OperationalCostType;
use App\Enums\PlanResource;
use App\Enums\Rules;
use App\Enums\SubscriptionStatus;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\File;
use App\Models\OperationalCost;
use App\Models\Partner;
use App\Models\PartnerDemand;
use App\Models\Plan;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\ServiceStep;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\PlanProfitabilityCalculator;
use App\Services\PlanLimits\TenantLimitChecker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DentistRequestFileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRule(Rules $ruleEnum): User
    {
        $rule = Rule::query()->firstOrCreate(['name' => $ruleEnum]);

        return User::factory()->create([
            'rule_id' => $rule->id,
        ]);
    }

    /**
     * @param  array<string, int|null>  $limits
     */
    protected function subscribeWithLimits(array $limits, SubscriptionStatus $status = SubscriptionStatus::Active): void
    {
        $plan = Plan::query()->create([
            'name' => 'Plano Storage Teste',
            'price_in_cents' => 10000,
            'billing_cycle' => 'monthly',
            'limits' => $limits,
        ]);

        Subscription::query()->where('tenant_id', $this->testTenant->getTenantKey())->delete();

        Subscription::query()->create([
            'tenant_id' => $this->testTenant->getTenantKey(),
            'plan_id' => $plan->id,
            'status' => $status,
            'stripe_subscription_id' => $status === SubscriptionStatus::Active ? 'sub_test_storage' : null,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    protected function createFakeS3File(string $path, int $sizeInBytes): void
    {
        Storage::disk('s3')->put($path, '');
        $fullPath = Storage::disk('s3')->path($path);
        $fp = fopen($fullPath, 'r+');
        ftruncate($fp, $sizeInBytes);
        fclose($fp);
        clearstatcache(true, $fullPath);
    }

    public function test_component_generates_presigned_upload_url_for_valid_3d_and_dicom_formats(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUser)->create();

        $component = Livewire::actingAs($dentistUser)->test('service.requesting');

        foreach (['model.stl', 'scan.ply', 'mesh.obj', 'ct_scan.dcm', 'study.dicom', 'arcada.stl.zip'] as $validFile) {
            $data = $component->instance()->getPreSignedUploadUrl($validFile, 'application/octet-stream', 1024);

            $this->assertArrayHasKey('url', $data);
            $this->assertArrayHasKey('path', $data);
            $this->assertNotEmpty($data['url']);
            $this->assertStringContainsString('requests/', $data['path']);
        }
    }

    public function test_component_rejects_unsupported_file_extensions_for_presigned_url(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUser)->create();

        $component = Livewire::actingAs($dentistUser)->test('service.requesting');

        $this->expectException(ValidationException::class);
        $component->instance()->getPreSignedUploadUrl('document.pdf', 'application/pdf', 1024);
    }

    public function test_get_presigned_upload_url_rejects_null_or_zero_file_size(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUser)->create();

        $component = Livewire::actingAs($dentistUser)->test('service.requesting');

        foreach ([null, 0, -512] as $invalidSize) {
            try {
                $component->instance()->getPreSignedUploadUrl('scan.stl.zip', 'application/zip', $invalidSize);
                $this->fail('Deveria lançar ValidationException para tamanho inválido.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('uploadedFiles', $e->errors());
            }
        }
    }

    public function test_get_presigned_upload_url_blocks_when_file_size_exceeds_storage_limit(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $this->subscribeWithLimits([PlanResource::Storage->value => 1]);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);

        // Ocupa 950 MB de 1 GB (1024 MB)
        $dentistRequest->files()->create([
            'name' => 'existente.stl',
            'path' => 'requests/'.tenant('id').'/existente.zip',
            'disk' => 's3',
            'size' => 950 * 1024 * 1024,
            'original_size' => 950 * 1024 * 1024,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        $component = Livewire::actingAs($dentistUser)->test('service.requesting');

        // 50 MB ainda cabem (950 + 50 = 1000 MB <= 1024 MB)
        $allowed = $component->instance()->getPreSignedUploadUrl('menor.stl.zip', 'application/zip', 50 * 1024 * 1024);
        $this->assertNotEmpty($allowed['url']);

        // 100 MB ultrapassam 1 GB (950 + 100 = 1050 MB > 1024 MB)
        $this->expectException(ValidationException::class);
        $component->instance()->getPreSignedUploadUrl('scan.stl.zip', 'application/zip', 100 * 1024 * 1024);
    }

    public function test_saving_request_creates_it_without_files(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $service = Service::factory()->create();

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->set('dentistId', $dentist->id)
            ->set('services.0.service_id', $service->id)
            ->set('services.0.unit_price', '150,00')
            ->set('services.0.quantity', 1)
            ->call('save')
            ->assertHasNoErrors();

        $dentistRequest = DentistRequest::where('dentist_id', $dentist->id)->first();
        $this->assertNotNull($dentistRequest);
        $this->assertCount(0, $dentistRequest->files);
    }

    public function test_add_files_to_request_persists_files_polymorphically(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $tenantId = tenant('id');

        $path1 = "requests/{$tenantId}/test-ulid_arcada_superior.zip";
        $path2 = "requests/{$tenantId}/test-ulid_tomografia.zip";
        $this->createFakeS3File($path1, 3145728);
        $this->createFakeS3File($path2, 15728640);

        $uploadedFiles = [
            [
                'name' => 'arcada_superior.stl',
                'zip_name' => 'arcada_superior.stl.zip',
                'path' => $path1,
                'size' => 3145728,
                'original_size' => 10485760,
                'extension' => 'stl',
                'disk' => 's3',
                'mime_type' => 'application/zip',
            ],
            [
                'name' => 'tomografia.dcm',
                'zip_name' => 'tomografia.dcm.zip',
                'path' => $path2,
                'size' => 15728640,
                'original_size' => 52428800,
                'extension' => 'dcm',
                'disk' => 's3',
                'mime_type' => 'application/zip',
            ],
        ];

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->call('addFilesToRequest', $dentistRequest->id, $uploadedFiles)
            ->assertHasNoErrors();

        $this->assertCount(2, $dentistRequest->fresh()->files);

        $this->assertDatabaseHas('files', [
            'fileable_type' => DentistRequest::class,
            'fileable_id' => $dentistRequest->id,
            'name' => 'arcada_superior.stl',
            'extension' => 'stl',
            'size' => 3145728,
            'original_size' => 10485760,
        ]);

        $this->assertDatabaseHas('files', [
            'fileable_type' => DentistRequest::class,
            'fileable_id' => $dentistRequest->id,
            'name' => 'tomografia.dcm',
            'extension' => 'dcm',
            'size' => 15728640,
            'original_size' => 52428800,
        ]);

        $fileModel = $dentistRequest->fresh()->files()->first();
        $this->assertEquals('3,00 MB', $fileModel->formatted_size);
        $this->assertEquals('10,00 MB', $fileModel->formatted_original_size);
    }

    public function test_add_files_to_request_blocks_when_storage_limit_exceeded_and_deletes_orphan_s3_objects(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $this->subscribeWithLimits([PlanResource::Storage->value => 1]);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $tenantId = tenant('id');

        // Ocupa 950 MB de 1 GB
        $dentistRequest->files()->create([
            'name' => 'existente.stl',
            'path' => "requests/{$tenantId}/existente.zip",
            'disk' => 's3',
            'size' => 950 * 1024 * 1024,
            'original_size' => 950 * 1024 * 1024,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        $orphanPath = "requests/{$tenantId}/orphan_upload.zip";
        $this->createFakeS3File($orphanPath, 100 * 1024 * 1024);
        Storage::disk('s3')->assertExists($orphanPath);

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->call('addFilesToRequest', $dentistRequest->id, [
                [
                    'name' => 'excesso.stl',
                    'zip_name' => 'excesso.stl.zip',
                    'path' => $orphanPath,
                    'size' => 100 * 1024 * 1024,
                    'original_size' => 200 * 1024 * 1024,
                    'extension' => 'stl',
                    'disk' => 's3',
                    'mime_type' => 'application/zip',
                ],
            ]);

        $this->assertDatabaseMissing('files', ['path' => $orphanPath]);
        Storage::disk('s3')->assertMissing($orphanPath);
    }

    public function test_add_files_to_request_prevents_idor_between_dentists(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUserA = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUserA)->create();

        $dentistUserB = $this->userWithRule(Rules::Dentist);
        $dentistB = Dentist::factory()->for($dentistUserB)->create();

        $dentistBRequest = DentistRequest::create(['dentist_id' => $dentistB->id]);
        $tenantId = tenant('id');

        $component = Livewire::actingAs($dentistUserA)->test('service.requesting');

        $this->expectException(AuthorizationException::class);

        try {
            $component->instance()->addFilesToRequest($dentistBRequest->id, [
                [
                    'name' => 'intruso.stl',
                    'path' => "requests/{$tenantId}/intruso.zip",
                    'size' => 1024,
                    'original_size' => 2048,
                    'extension' => 'stl',
                    'disk' => 's3',
                    'mime_type' => 'application/zip',
                ],
            ]);
        } finally {
            $this->assertSame(0, $dentistBRequest->fresh()->files()->count());
        }
    }

    public function test_remove_s3_file_prevents_idor_and_arbitrary_s3_deletion(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $tenantId = tenant('id');

        $dentistUserA = $this->userWithRule(Rules::Dentist);
        $dentistA = Dentist::factory()->for($dentistUserA)->create();
        $requestA = DentistRequest::create(['dentist_id' => $dentistA->id]);

        $dentistUserB = $this->userWithRule(Rules::Dentist);
        $dentistB = Dentist::factory()->for($dentistUserB)->create();
        $requestB = DentistRequest::create(['dentist_id' => $dentistB->id]);

        $pathB = "requests/{$tenantId}/dentist_b_file.zip";
        Storage::disk('s3')->put($pathB, 'secret-3d-scan');

        $fileB = $requestB->files()->create([
            'name' => 'scan_b.stl',
            'path' => $pathB,
            'disk' => 's3',
            'size' => 4096,
            'original_size' => 8192,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        $component = Livewire::actingAs($dentistUserA)->test('service.requesting');

        // Caso 1: Dentista A tenta remover arquivo da DentistRequest do Dentista B -> 403
        try {
            $component->instance()->removeS3File($requestB->id, $pathB);
            $this->fail('Deveria lançar AuthorizationException quando Dentista A tenta alterar request do Dentista B.');
        } catch (AuthorizationException) {
            Storage::disk('s3')->assertExists($pathB);
            $this->assertDatabaseHas('files', ['id' => $fileB->id]);
        }

        // Caso 2: Dentista A passa seu próprio requestId, mas informa o path do arquivo do Dentista B -> 404 sem apagar S3
        try {
            $component->instance()->removeS3File($requestA->id, $pathB);
            $this->fail('Deveria lançar ModelNotFoundException quando o path não pertence à DentistRequest.');
        } catch (ModelNotFoundException) {
            Storage::disk('s3')->assertExists($pathB);
            $this->assertDatabaseHas('files', ['id' => $fileB->id]);
        }

        // Caso 3: Dentista B (dono legítimo) remove seu próprio arquivo -> sucesso
        $componentB = Livewire::actingAs($dentistUserB)->test('service.requesting');
        $componentB->instance()->removeS3File($requestB->id, $pathB);

        Storage::disk('s3')->assertMissing($pathB);
        $this->assertDatabaseMissing('files', ['id' => $fileB->id]);
    }

    public function test_finish_demand_registers_photo_in_files_table_and_enforces_storage_limit(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $this->subscribeWithLimits([PlanResource::Storage->value => 1]);

        OperationalCost::factory()->create([
            'name' => 'AWS S3 por GB',
            'type' => OperationalCostType::PerStorageGb,
            'amount' => 1000, // R$ 10,00 / GB
            'is_active' => true,
        ]);

        $labUser = $this->userWithRule(Rules::Lab);
        $partner = Partner::factory()->for($this->userWithRule(Rules::LabPartner))->create(['is_active' => true]);
        $dentist = Dentist::factory()->for($this->userWithRule(Rules::Dentist))->create();
        $service = Service::factory()->create();
        $stepOne = ServiceStep::factory()->create();
        $stepTwo = ServiceStep::factory()->create();

        $service->serviceSteps()->attach($stepOne->id, ['order' => 1]);
        $service->serviceSteps()->attach($stepTwo->id, ['order' => 2]);

        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $requestService = RequestService::create([
            'dentist_request_id' => $dentistRequest->id,
            'service_id' => $service->id,
            'unit_price' => 200,
            'quantity' => 1,
        ]);

        $demands = $requestService->partnerDemands()->orderBy('order')->get();
        $demand = $demands->first();
        $demand->update([
            'partner_id' => $partner->id,
            'started_at' => now()->subHour(),
        ]);

        $photo = UploadedFile::fake()->image('resultado.jpg')->size(2048); // ~2 MB

        Livewire::actingAs($labUser)
            ->test('partners.finish-demand')
            ->call('openModal', $demand->id)
            ->set('image', $photo)
            ->call('finishDemand')
            ->assertHasNoErrors();

        $demand->refresh();
        $this->assertNotNull($demand->ended_at);
        $this->assertNotNull($demand->result_photo_path);
        Storage::disk('s3')->assertExists($demand->result_photo_path);

        $fileRecord = File::where('fileable_type', PartnerDemand::class)
            ->where('fileable_id', $demand->id)
            ->first();

        $this->assertNotNull($fileRecord);
        $this->assertSame($demand->result_photo_path, $fileRecord->path);
        $this->assertGreaterThan(0, $fileRecord->size);

        $expectedGb = $fileRecord->size / (1024 ** 3);
        $actualConsumption = app(TenantLimitChecker::class)->consumptionFor(PlanResource::Storage);
        $this->assertEqualsWithDelta($expectedGb, $actualConsumption, 0.000001);

        $overview = app(PlanProfitabilityCalculator::class)->overview(forceRefresh: true);
        $this->assertEqualsWithDelta(round($expectedGb, 4), $overview->totalStorageGb, 0.0001);

        // Agora tenta finalizar a segunda demanda quando o tenant já está próximo do limite de 1 GB
        $dentistRequest->files()->create([
            'name' => 'quase_cheio.stl',
            'path' => 'requests/'.tenant('id').'/quase_cheio.zip',
            'disk' => 's3',
            'size' => (1024 * 1024 * 1024) - $fileRecord->size - 512, // Deixa apenas 512 bytes livres para atingir 1 GB
            'original_size' => 1024 * 1024 * 1024,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        $secondDemand = $demands->last();
        $secondDemand->update([
            'partner_id' => $partner->id,
            'started_at' => now()->subMinutes(30),
        ]);

        $secondPhoto = UploadedFile::fake()->image('bloqueada.jpg')->size(1024); // ~1 MB

        Livewire::actingAs($labUser)
            ->test('partners.finish-demand')
            ->call('openModal', $secondDemand->id)
            ->set('image', $secondPhoto)
            ->call('finishDemand');

        $secondDemand->refresh();
        $this->assertNull($secondDemand->ended_at);
        $this->assertNull($secondDemand->result_photo_path);
        $this->assertSame(0, $secondDemand->files()->count());
    }

    public function test_request_files_component_authorizes_view_and_download_by_instance(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUserA = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUserA)->create();

        $dentistUserB = $this->userWithRule(Rules::Dentist);
        $dentistB = Dentist::factory()->for($dentistUserB)->create();
        $requestB = DentistRequest::create(['dentist_id' => $dentistB->id]);

        Livewire::actingAs($dentistUserA)
            ->test('service.request-files')
            ->call('loadRequest', $requestB->id)
            ->assertForbidden();

        Livewire::actingAs($dentistUserB)
            ->test('service.request-files')
            ->call('loadRequest', $requestB->id)
            ->assertOk();
    }

    public function test_save_sets_created_request_id_for_upload_modal(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $service = Service::factory()->create();

        $component = Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->set('dentistId', $dentist->id)
            ->set('services.0.service_id', $service->id)
            ->set('services.0.unit_price', '150,00')
            ->set('services.0.quantity', 1)
            ->call('save')
            ->assertHasNoErrors();

        $dentistRequest = DentistRequest::where('dentist_id', $dentist->id)->first();
        $component->assertSet('createdRequestId', $dentistRequest->id);
    }

    public function test_add_files_to_request_ignores_client_provided_size_and_uses_s3_size(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $tenantId = tenant('id');

        $path = "requests/{$tenantId}/real_3mb_scan.zip";
        $realSize = 3145728; // 3 MB
        $this->createFakeS3File($path, $realSize);

        // Cliente tenta falsificar 'size' => 1 e 'original_size' => 500 (menor que o real)
        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->call('addFilesToRequest', $dentistRequest->id, [
                [
                    'name' => 'arcada.stl',
                    'path' => $path,
                    'size' => 1,
                    'original_size' => 500,
                    'extension' => 'stl',
                    'disk' => 's3',
                    'mime_type' => 'application/zip',
                ],
            ])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('files', [
            'fileable_type' => DentistRequest::class,
            'fileable_id' => $dentistRequest->id,
            'name' => 'arcada.stl',
            'path' => $path,
            'size' => $realSize,
            'original_size' => $realSize, // Sanitizado para max(actualSize, reportedOriginalSize)
        ]);
    }

    public function test_add_files_to_request_blocks_quota_bypass_attempt_with_spoofed_size(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $this->subscribeWithLimits([PlanResource::Storage->value => 1]); // 1 GB limite

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $tenantId = tenant('id');

        // Ocupa 950 MB
        $dentistRequest->files()->create([
            'name' => 'anterior.stl',
            'path' => "requests/{$tenantId}/anterior.zip",
            'disk' => 's3',
            'size' => 950 * 1024 * 1024,
            'original_size' => 950 * 1024 * 1024,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        // Arquivo real de 100 MB no S3 (950 + 100 = 1050 MB > 1024 MB)
        $spoofedPath = "requests/{$tenantId}/malicious_large_file.zip";
        $this->createFakeS3File($spoofedPath, 100 * 1024 * 1024);
        Storage::disk('s3')->assertExists($spoofedPath);

        // Cliente envia 'size' => 1 tentando burlar a cota
        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->call('addFilesToRequest', $dentistRequest->id, [
                [
                    'name' => 'malicious.stl',
                    'path' => $spoofedPath,
                    'size' => 1,
                    'original_size' => 1,
                    'extension' => 'stl',
                    'disk' => 's3',
                    'mime_type' => 'application/zip',
                ],
            ]);

        // Verificações: não salva em banco e apaga objeto órfão do S3
        $this->assertDatabaseMissing('files', ['path' => $spoofedPath]);
        Storage::disk('s3')->assertMissing($spoofedPath);
    }

    public function test_add_files_to_request_rejects_non_existent_s3_files(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $tenantId = tenant('id');

        $nonExistentPath = "requests/{$tenantId}/ghost_file.zip";

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->call('addFilesToRequest', $dentistRequest->id, [
                [
                    'name' => 'ghost.stl',
                    'path' => $nonExistentPath,
                    'size' => 1024,
                    'disk' => 's3',
                ],
            ])
            ->assertHasErrors(['uploadedFiles']);

        $this->assertDatabaseMissing('files', ['path' => $nonExistentPath]);
    }

    public function test_add_files_to_request_rejects_empty_s3_files_and_cleans_them_up(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $tenantId = tenant('id');

        $emptyPath = "requests/{$tenantId}/empty_file.zip";
        Storage::disk('s3')->put($emptyPath, ''); // 0 bytes
        Storage::disk('s3')->assertExists($emptyPath);

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->call('addFilesToRequest', $dentistRequest->id, [
                [
                    'name' => 'empty.stl',
                    'path' => $emptyPath,
                    'size' => 1024,
                    'disk' => 's3',
                ],
            ])
            ->assertHasErrors(['uploadedFiles']);

        // Deve apagar o arquivo vazio e não salvar no banco
        Storage::disk('s3')->assertMissing($emptyPath);
        $this->assertDatabaseMissing('files', ['path' => $emptyPath]);
    }

    public function test_storage_consumption_ignores_non_positive_file_sizes(): void
    {
        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $dentistRequest = DentistRequest::create(['dentist_id' => $dentist->id]);
        $tenantId = tenant('id');

        // Cria um arquivo legítimo de 1 MB (1048576 bytes)
        $dentistRequest->files()->create([
            'name' => 'legit.stl',
            'path' => "requests/{$tenantId}/legit.zip",
            'disk' => 's3',
            'size' => 1048576,
            'original_size' => 1048576,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        // Cria arquivo com size = 0
        $dentistRequest->files()->create([
            'name' => 'zero.stl',
            'path' => "requests/{$tenantId}/zero.zip",
            'disk' => 's3',
            'size' => 0,
            'original_size' => 0,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        // Cria arquivo com size = -500 (caso anômalo legado)
        $dentistRequest->files()->create([
            'name' => 'negative.stl',
            'path' => "requests/{$tenantId}/negative.zip",
            'disk' => 's3',
            'size' => -500,
            'original_size' => 0,
            'extension' => 'stl',
            'mime_type' => 'application/zip',
        ]);

        $consumptionGb = app(TenantLimitChecker::class)->consumptionFor(PlanResource::Storage);

        $expectedGb = 1048576 / (1024 ** 3);
        $this->assertEqualsWithDelta($expectedGb, $consumptionGb, 0.0000001);
    }
}
