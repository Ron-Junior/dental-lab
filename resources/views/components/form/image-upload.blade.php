@props([
    'name' => 'image',
    'id' => 'image-upload',
    'width' => 1200,
    'height' => 1200,
    'quality' => 0.85,
    'label' => 'Upload de imagem',
    'description' => 'PNG, JPG ou WEBP até 10MB',
])

@php
    $wireModel =$attributes->wire('model');
    $wireModelName =$wireModel->value();
@endphp

<div 
    x-data="fluxImageWebpUploader({
        modelName: '{{ $wireModelName }}',
        targetWidth: {{ $width }},
        targetHeight: {{ $height }},
        quality: {{ $quality }}
    })" 
    {{ $attributes->whereDoesntStartWith('wire:model') }}
    class="w-full space-y-2"
>
    <!-- Label -->
    <label for="{{ $id }}" class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">
        {{ $label }}
    </label>

    <!-- Zona de Dropzone (Flux UI Style) -->
    <div 
        x-show="!previewUrl"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="handleDrop($event)"
        @click="$refs.fileInput.click()"
        :class="{
            'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-950/20 dark:border-indigo-400': isDragging,
            'border-zinc-300 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-900/50 hover:border-zinc-400 dark:hover:border-zinc-600': !isDragging
        }"
        class="relative flex flex-col items-center justify-center p-6 text-center border-2 border-dashed rounded-xl cursor-pointer transition-all duration-150 ease-in-out group"
    >
        <input 
            x-ref="fileInput"
            type="file" 
            id="{{ $id }}" 
            accept="image/*" 
            @change="processFile($event.target.files[0])"
            class="hidden"
        >

        <!-- Ícone de Upload -->
        <div class="p-3 mb-2 rounded-full bg-white dark:bg-zinc-800 shadow-xs border border-zinc-200 dark:border-zinc-700 group-hover:scale-105 transition-transform">
            <svg class="w-5 h-5 text-zinc-500 dark:text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
            </svg>
        </div>

        <div class="text-sm text-zinc-600 dark:text-zinc-400">
            <span class="font-semibold text-zinc-900 dark:text-zinc-100 underline decoration-zinc-300 underline-offset-2 group-hover:decoration-zinc-400">
                Clique para selecionar
            </span> 
            ou me arraste até aqui
        </div>

        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            {{ $description }} (WebP {{ $width }}x{{$height }}px)
        </p>

        <!-- Indicador de Carregamento/Upload -->
        <div 
            x-show="isProcessing" 
            x-transition 
            class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-zinc-900/80 rounded-xl backdrop-blur-xs"
        >
            <div class="flex items-center gap-2 text-sm font-medium text-indigo-600 dark:text-indigo-400">
                <svg class="w-5 h-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span x-text="statusText"></span>
            </div>
        </div>
    </div>

    <!-- Card de Pré-visualização -->
    <template x-if="previewUrl">
        <div class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800/80 rounded-xl shadow-xs">
            <div class="flex items-center gap-3 overflow-hidden">
                <img :src="previewUrl" class="w-12 h-12 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700 shrink-0" alt="Preview">
                <div class="truncate">
                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 truncate" x-text="fileName || 'Imagem WebP'"></p>
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 flex items-center gap-1.5">
                        <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        WebP • {{ $width }}x{{$height }}px
                    </p>
                </div>
            </div>

            <button 
                type="button" 
                @click="removeImage"
                class="p-1.5 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-700/50 transition-colors"
                title="Remover imagem"
            >
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('fluxImageWebpUploader', (config) => ({
        isDragging: false,
        isProcessing: false,
        statusText: 'Convertendo para WebP...',
        previewUrl: null,
        fileName: '',
        modelName: config.modelName,
        targetWidth: config.targetWidth,
        targetHeight: config.targetHeight,
        quality: config.quality,

        handleDrop(event) {
            this.isDragging = false;
            const file = event.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
                this.processFile(file);
            }
        },

        processFile(file) {
            if (!file) return;

            const baseName = file.name.replace(/\.[^/.]+$/, "");
            this.fileName = baseName + ".webp";
            this.isProcessing = true;
            this.statusText = 'Convertendo para WebP...';

            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = this.targetWidth;
                    canvas.height = this.targetHeight;

                    const ctx = canvas.getContext('2d');

                    // Recorte Proporcional (Crop Cover Centralizado)
                    let sourceX = 0, sourceY = 0;
                    let sourceWidth = img.width, sourceHeight = img.height;

                    const aspectSource = img.width / img.height;
                    const aspectTarget = this.targetWidth / this.targetHeight;

                    if (aspectSource > aspectTarget) {
                        sourceWidth = img.height * aspectTarget;
                        sourceX = (img.width - sourceWidth) / 2;
                    } else {
                        sourceHeight = img.width / aspectTarget;
                        sourceY = (img.height - sourceHeight) / 2;
                    }

                    ctx.drawImage(
                        img, 
                        sourceX, sourceY, sourceWidth, sourceHeight, 
                        0, 0, this.targetWidth, this.targetHeight
                    );

                    // Converte o Canvas em um arquivo Blob real (WebP)
                    canvas.toBlob((blob) => {
                        if (!blob) return;

                        // Cria um objeto File nativo a partir do Blob retornado
                        const webpFile = new File([blob], this.fileName, { type: 'image/webp' });
                        this.previewUrl = URL.createObjectURL(blob);

                        // Dispara o Upload usando o driver do Livewire
                        this.uploadToLivewire(webpFile);
                    }, 'image/webp', this.quality);
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        },

        uploadToLivewire(file) {
            this.statusText = 'Enviando...';

            // Chama a API de upload nativa do Livewire via JavaScript
            this.$wire.upload(
                this.modelName, 
                file, 
                (uploadedFilename) => {
                    // Sucesso
                    this.isProcessing = false;
                },
                () => {
                    // Erro
                    this.isProcessing = false;
                    alert('Falha ao enviar a imagem.');
                },
                (event) => {
                    // Progresso de upload
                    this.statusText = `Enviando... ${event.detail.progress}%`;
                }
            );
        },

        removeImage() {
            this.previewUrl = null;
            this.fileName = '';
            
            // Limpa a propriedade no Livewire chamando $wire.set
            this.$wire.set(this.modelName, null);

            if (this.$refs.fileInput) {
                this.$refs.fileInput.value = '';
            }
        }
    }));
});
</script>