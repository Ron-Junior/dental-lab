<?php

use App\Models\DentistRequest;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?int $requestId = null;
    public ?DentistRequest $dentistRequest = null;

    #[On('service-request::files::open')]
    public function loadRequest(int $requestId): void
    {
        $this->requestId = $requestId;
        $this->dentistRequest = DentistRequest::with('files')->find($requestId);

        if (!$this->dentistRequest) {
            Flux::toast(variant: 'danger', heading: 'Erro', text: 'Solicitação não encontrada.');
            return;
        }

        Flux::modal('show-request-files-modal')->show();
    }

    public function download(int $fileId)
    {
        if (!$this->dentistRequest) {
            return;
        }

        $this->authorize('view', $this->dentistRequest);

        $file = $this->dentistRequest->files()->findOrFail($fileId);
        
        $diskName = $file->disk ?? config('filesystems.default', 's3');
        $disk = Storage::disk($diskName);

        if (!$disk->exists($file->path)) {
            Flux::toast(variant: 'danger', heading: 'Erro', text: 'O arquivo não foi encontrado no storage.');
            return;
        }

        $url = $disk->temporaryUrl(
            $file->path,
            now()->addMinutes(5),
            [
                'Response-Content-Disposition' => 'attachment; filename="' . ($file->name . '.zip') . '"'
            ]
        );

        return redirect()->to($url);
    }
};

?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg" flyout variant="floating" name="show-request-files-modal">
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Arquivos da Solicitação</flux:heading>
                    <flux:subheading>Gerencie e faça o download dos arquivos anexados.</flux:subheading>
                </div>
            </div>

            <flux:separator />

            @if($dentistRequest)
                <div class="space-y-3">
                    <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400 px-1">
                        <span>Arquivos Anexados</span>
                        <span>{{ $dentistRequest->files->count() }} {{ $dentistRequest->files->count() === 1 ? 'item' : 'itens' }}</span>
                    </div>

                    <div class="space-y-2">
                        @forelse ($dentistRequest->files as $file)
                            <div class="flex items-center justify-between gap-3 p-3 border border-zinc-200 dark:border-zinc-700/85 bg-white dark:bg-zinc-800/80 rounded-xl shadow-xs">
                                <div class="flex items-center gap-3 min-w-0">
                                    <!-- Ícone / Extensão -->
                                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 font-bold text-xs bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200/50 dark:border-indigo-800">
                                        {{ strtoupper($file->extension ?? 'ZIP') }}
                                    </div>

                                    <!-- Detalhes -->
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 truncate">
                                            {{ $file->name }}
                                        </p>
                                        <div class="flex items-center flex-wrap gap-x-2 gap-y-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                            @if ($file->size)
                                                <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                                    {{ number_format($file->size / 1024 / 1024, 2) }} MB
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <!-- Botão de Download -->
                                <div class="shrink-0">
                                    <flux:button 
                                        wire:click="download({{ $file->id }})" 
                                        variant="outline" 
                                        icon="arrow-down-tray" 
                                        size="sm"
                                    >
                                        Baixar
                                    </flux:button>
                                </div>
                            </div>
                        @empty
                            <x-empty-state
                                icon="paper-clip"
                                title="Nenhum arquivo anexado"
                                description="Nenhum arquivo anexado a esta solicitação."
                            />
                        @endforelse
                    </div>
                </div>
            @else
                <div class="p-8 text-center text-zinc-400">
                    Carregando arquivos...
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-4">
                <flux:modal.close>
                    <flux:button variant="ghost">Fechar</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>