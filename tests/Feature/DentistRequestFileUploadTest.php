<?php

namespace Tests\Feature;

use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Rule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $component->instance()->getPreSignedUploadUrl('document.pdf', 'application/pdf');
    }

    public function test_saving_request_persists_uploaded_files_polymorphically(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        $dentist = Dentist::factory()->for($dentistUser)->create();
        $service = Service::factory()->create();

        $uploadedFiles = [
            [
                'name' => 'arcada_superior.stl',
                'zip_name' => 'arcada_superior.stl.zip',
                'path' => 'requests/tenant-1/test-ulid_arcada_superior.zip',
                'size' => 3145728, // 3MB compressed
                'original_size' => 10485760, // 10MB original
                'extension' => 'stl',
                'disk' => 's3',
                'mime_type' => 'application/zip',
            ],
            [
                'name' => 'tomografia.dcm',
                'zip_name' => 'tomografia.dcm.zip',
                'path' => 'requests/tenant-1/test-ulid_tomografia.zip',
                'size' => 15728640, // 15MB compressed
                'original_size' => 52428800, // 50MB original
                'extension' => 'dcm',
                'disk' => 's3',
                'mime_type' => 'application/zip',
            ],
        ];

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->set('dentistId', $dentist->id)
            ->set('services.0.service_id', $service->id)
            ->set('services.0.unit_price', '150,00')
            ->set('services.0.quantity', 1)
            ->set('uploadedFiles', $uploadedFiles)
            ->call('save')
            ->assertHasNoErrors();

        $dentistRequest = DentistRequest::where('dentist_id', $dentist->id)->first();
        $this->assertNotNull($dentistRequest);

        $this->assertCount(2, $dentistRequest->files);

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

        $fileModel = $dentistRequest->files()->first();
        $this->assertEquals('3,00 MB', $fileModel->formatted_size);
        $this->assertEquals('10,00 MB', $fileModel->formatted_original_size);
    }

    public function test_removing_uploaded_file_deletes_from_storage(): void
    {
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUser)->create();

        $filePath = 'requests/tenant-1/scan_to_delete.stl';
        Storage::disk('s3')->put($filePath, 'dummy content');
        $this->assertTrue(Storage::disk('s3')->exists($filePath));

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->set('uploadedFiles', [
                [
                    'name' => 'scan_to_delete.stl',
                    'path' => $filePath,
                    'disk' => 's3',
                    'size' => 100,
                    'extension' => 'stl',
                ],
            ])
            ->call('removeFile', 0)
            ->assertSet('uploadedFiles', []);

        $this->assertFalse(Storage::disk('s3')->exists($filePath));
    }
}
