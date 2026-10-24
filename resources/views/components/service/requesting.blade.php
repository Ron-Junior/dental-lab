<?php

use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\File;
use App\Models\RequestService;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate(['required', 'exists:dentists,id'])]
    public ?int $dentistId = null;

    public array $uploadedFiles = [];
    public array $services = [
        [
            'service_id' => '',
            'unit_price' => '',
            'quantity' => '',
        ],
    ];

    public string $searchDentist = '';

    public string $searchService = '';

    /** ID da request recém-criada, para exibir o modal de upload após salvar. */
    #[Locked]
    public ?int $createdRequestId = null;

    public function rules(): array
    {
        return [
            'services' => 'array|min:1',
            'services.*.service_id' => 'required|exists:services,id',
            'services.*.unit_price' => 'required',
            'services.*.quantity' => 'required|integer|min:1|max:1000000',
        ];
    }

    public function messages(): array
    {
        return [
            'services.*.service_id.required' => 'O serviço é obrigatório.',
            'services.*.service_id.exists' => 'O serviço selecionado é inválido.',
            'services.*.unit_price.required' => 'O preço unitário é obrigatório.',
            'services.*.quantity.required' => 'A quantidade é obrigatória.',
            'services.*.quantity.integer' => 'A quantidade deve ser um número inteiro.',
            'services.*.quantity.min' => 'A quantidade deve ser maior ou igual a 1.',
            'services.*.quantity.max' => 'A quantidade deve ser menor ou igual a 1000000.',
        ];
    }

    public function mount()
    {
        if (Auth::user()->can('viewAny', \App\Models\Dentist::class)) {
            return;
        }

        $this->authorize('create', DentistRequest::class);

        $this->dentistId = Auth::user()->dentist?->id;
    }

    #[On('remove-s3-file')]
    public function removeS3File($requestId, $path)
    {
    
        Storage::disk('s3')->delete($path);

        File::where('fileable_id', $requestId)
            ->where('fileable_type', DentistRequest::class)
            ->where('path', $path)
            ->first()
            ->delete();

        Flux::toast(text: 'Arquivo removido!', variant: 'success');
    }

    #[Computed(true)]
    public function dentists(): Collection
    {
        return Dentist::with('user')->whereRelation('user', 'name', 'like', '%'.$this->searchDentist.'%')->get();
    }

    #[Computed(true)]
    public function offerServices(): Collection
    {
        return Service::all();
    }

    public function updatedServices($value, $key): void
    {
        [$index, $field] = explode('.', $key);

        if ($field === 'unit_price' || $field === 'quantity') {
            return;
        }

        $selectedService = $this->offerServices->firstWhere('id', $value);
        $this->services[$index]['unit_price'] = number_format($selectedService?->price, 2, ',', '.') ?? 0;
    }

    public function addService(): void
    {
        $this->services[] = [
            'service_id' => '',
            'unit_price' => '',
            'quantity' => '',
        ];
    }

    public function removeService(int $index): void
    {
        unset($this->services[$index]);
    }

    public function getPreSignedUploadUrl(string $filename, string $contentType = '', ?int $fileSize = null): array
    {
        $this->authorize('create', DentistRequest::class);

        $cleanName = strtolower($filename);
        $isZip = str_ends_with($cleanName, '.zip');

        if ($isZip) {
            $withoutZip = substr($cleanName, 0, -4);
            $innerExt = pathinfo($withoutZip, PATHINFO_EXTENSION);
            $extension = in_array($innerExt, ['stl', 'ply', 'obj', 'dcm', 'dicom'], true) ? $innerExt : 'zip';
        } else {
            $extension = pathinfo($cleanName, PATHINFO_EXTENSION);
        }

        $allowedExtensions = ['stl', 'ply', 'obj', 'dcm', 'dicom', 'zip'];

        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'uploadedFiles' => 'Formato não permitido. Formatos aceitos: STL, PLY, OBJ e DICOM (DCM).',
            ]);
        }

        $tenantId = tenant('id') ?? 'default';
        $safeFilename = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        $uniqueId = (string) Str::ulid();
        $targetExtension = $isZip ? 'zip' : $extension;
        $path = sprintf('requests/%s/%s_%s.%s', $tenantId, $uniqueId, $safeFilename ?: 'scan', $targetExtension);

        $diskName = config('filesystems.default', 's3');
        $disk = Storage::disk($diskName);

        $upload = $disk->temporaryUploadUrl(
            $path,
            now()->addMinutes(30)
        );

        return [
            'url' => $upload['url'],
            'headers' => $upload['headers'] ?? [],
            'path' => $path,
            'disk' => $diskName,
        ];
    }

    /**
     * Persiste os arquivos já enviados ao S3 na request recém-criada.
     * Chamado pelo uploader após o upload direto ao S3 com sucesso.
     */
    public function addFilesToRequest(int $requestId, array $files): void
    {
        $this->authorize('create', DentistRequest::class);

        $dentistRequest = DentistRequest::findOrFail($requestId);

        foreach ($files as $fileData) {
            $dentistRequest->files()->create([
                'name' => $fileData['name'],
                'path' => $fileData['path'],
                'disk' => $fileData['disk'] ?? config('filesystems.default', 's3'),
                'size' => $fileData['size'] ?? null,
                'original_size' => $fileData['original_size'] ?? null,
                'extension' => $fileData['extension'] ?? null,
                'mime_type' => $fileData['mime_type'] ?? 'application/zip',
            ]);
        }
    }

    public function cancel(): void
    {
        $this->reset();
        $this->resetValidation();
    }

    public function closeUploadModal(): void
    {
        $this->createdRequestId = null;
        Flux::toast(variant: 'success', text: "Arquivos adicionados!");
        $this->dispatch('requests::refresh');
    }
    
    public function save(): void
    {
        $this->authorize('create', DentistRequest::class);

        if (Auth::user()->rule->name === Rules::Dentist) {
            $this->dentistId = Auth::user()->dentist?->id;
        }

        $this->validate();

        DB::transaction(function () {
            $dentistRequest = DentistRequest::create([
                'dentist_id' => $this->dentistId,
            ]);

            foreach ($this->services as $service) {
                RequestService::create([
                    'dentist_request_id' => $dentistRequest->id,
                    'service_id' => $service['service_id'],
                    'unit_price' => str($service['unit_price'])->replaceFirst('.', '')->replaceLast(',', '.')->toFloat(),
                    'quantity' => $service['quantity'],
                ]);
            }

            $this->createdRequestId = $dentistRequest->id;
        });

        Flux::modal('store-service-modal')->close();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Pedido criado com sucesso!');
        $this->reset(['services', 'dentistId', 'searchDentist', 'searchService']);
        $this->resetValidation();
        Flux::modal('upload-files-modal')->show();
    }
};

?>
<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg" flyout variant="floating" name="store-service-modal" @cancel="cancel">
        <form wire:submit="save" class="space-y-6" wire:key="store-service-modal">
            <flux:heading size="lg">Nova Solicitação</flux:heading>
            <flux:subheading>Adicione uma nova solicitação de serviço.</flux:subheading>

            @can('viewAny', \App\Models\Dentist::class)
                <flux:select label="Dentista" wire:model="dentistId">
                    <flux:select.option>Selecione um Dentista</flux:select.option>
                    @foreach ($this->dentists as $dentist)
                        <flux:select.option value="{{ $dentist->id }}">{{ $dentist->user->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endcan

            <flux:heading size="lg">Serviços</flux:heading>

            @foreach ($this->services as $index => $service)
                <flux:card>
                    <div class="space-y-4">

                        <flux:select label="Serviço" wire:model.live="services.{{ $index }}.service_id">
                            <flux:select.option>Selecione um Serviço</flux:select.option>
                            @foreach ($this->offerServices as $offerService)
                                <flux:select.option value="{{ $offerService->id }}">{{ $offerService->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input mask:dynamic="$money($input, ',', '.')" label="Preço Unitário" wire:model="services.{{ $index }}.unit_price" readonly />            
                        <flux:input label="Quantidade" type="number" wire:model="services.{{ $index }}.quantity" />

                        @if (count($services) > 1)
                            <flux:button wire:click="removeService('{{ $index }}')" variant="outline" icon="trash" class="w-full">
                                Remover Serviço
                            </flux:button>
                        @endif
                    </div>
                </flux:card>
            @endforeach

            <flux:button class="w-full" icon="plus" variant="outline" wire:click="addService">Adicionar Serviço</flux:button>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit">Salvar</flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($createdRequestId)
        <flux:modal
            class="backdrop:backdrop-blur-sm md:w-lg z-10"
            flyout
            variant="floating"
            name="upload-files-modal"
            @cancel="$wire.closeUploadModal()"
        >
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Adicionar Arquivos 3D</flux:heading>
                    <flux:subheading>Envie os escaneamentos (STL, PLY, OBJ) ou tomografias (DICOM) da solicitação. O upload é feito automaticamente ao selecionar os arquivos.</flux:subheading>
                </div>

                <x-form.s3-file-upload
                    :request-id="$createdRequestId"
                    auto-upload
                />

                <div class="flex items-center justify-end gap-2 pt-2">
                    <flux:modal.close @click="$wire.closeUploadModal()">
                        <flux:button>Concluir</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endif
</div>