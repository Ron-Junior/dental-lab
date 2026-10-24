@props([
    'name' => 'uploadedFiles',
    'id' => 's3-file-upload',
    'label' => 'Arquivos 3D e Tomografias',
    'description' => 'Arquivos STL, PLY, OBJ ou DICOM (.dcm) compactados em ZIP automaticamente',
    'accept' => '.stl,.ply,.obj,.dcm,.dicom',
    'multiple' => true,
])

@php
    $wireModel = $attributes->wire('model');
    $wireModelName = $wireModel->value() ?: $name;
@endphp

<div 
    x-data="s3DirectUploader({
        modelName: '{{ $wireModelName }}'
    })"
    {{ $attributes->whereDoesntStartWith('wire:model') }}
    class="w-full space-y-3"
>
    <!-- Label -->
    <div class="flex items-center justify-between">
        <label for="{{ $id }}" class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">
            {{ $label }}
        </label>
        <span class="text-xs text-zinc-400 dark:text-zinc-500">
            Compactação ZIP no navegador &bull; Envio direto à nuvem
        </span>
    </div>

    <!-- Zona de Dropzone (Flux UI Style) -->
    <div 
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="handleDrop($event)"
        @click="$refs.fileInput.click()"
        :class="{
            'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-950/20 dark:border-indigo-400 ring-2 ring-indigo-500/20': isDragging,
            'border-zinc-300 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-900/50 hover:border-zinc-400 dark:hover:border-zinc-600': !isDragging
        }"
        class="relative flex flex-col items-center justify-center p-6 text-center border-2 border-dashed rounded-xl cursor-pointer transition-all duration-150 ease-in-out group"
    >
        <input 
            x-ref="fileInput"
            type="file" 
            id="{{ $id }}" 
            accept="{{ $accept }}" 
            @if ($multiple) multiple @endif
            @change="handleFileSelect($event)"
            class="hidden"
        >

        <!-- Ícone de Upload 3D / Arquivo -->
        <div class="p-3 mb-2 rounded-full bg-white dark:bg-zinc-800 shadow-xs border border-zinc-200 dark:border-zinc-700 group-hover:scale-105 transition-transform text-indigo-600 dark:text-indigo-400">
            <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
            </svg>
        </div>

        <div class="text-sm text-zinc-600 dark:text-zinc-400">
            <span class="font-semibold text-zinc-900 dark:text-zinc-100 underline decoration-zinc-300 underline-offset-2 group-hover:decoration-zinc-400">
                Clique para selecionar
            </span> 
            ou arraste os arquivos até aqui
        </div>

        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
            {{ $description }}
        </p>

        <!-- Formatos Suportados -->
        <div class="flex items-center gap-1.5 mt-3">
            <span class="px-2 py-0.5 text-[11px] font-semibold tracking-wider rounded-md bg-zinc-200/80 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-300/60 dark:border-zinc-700">STL</span>
            <span class="px-2 py-0.5 text-[11px] font-semibold tracking-wider rounded-md bg-zinc-200/80 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-300/60 dark:border-zinc-700">PLY</span>
            <span class="px-2 py-0.5 text-[11px] font-semibold tracking-wider rounded-md bg-zinc-200/80 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-300/60 dark:border-zinc-700">OBJ</span>
            <span class="px-2 py-0.5 text-[11px] font-semibold tracking-wider rounded-md bg-zinc-200/80 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-300/60 dark:border-zinc-700">DICOM</span>
            <span class="px-2 py-0.5 text-[11px] font-semibold tracking-wider rounded-md bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                Auto ZIP
            </span>
        </div>
    </div>

    <!-- Lista de Arquivos -->
    <template x-if="files.length > 0">
        <div class="space-y-2">
            <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400 px-1">
                <span>Arquivos (<span x-text="files.length"></span>)</span>
                <span x-show="isProcessing" class="text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processando arquivos...
                </span>
            </div>

            <template x-for="item in files" :key="item.id">
                <div class="relative overflow-hidden p-3 border border-zinc-200 dark:border-zinc-700/80 bg-white dark:bg-zinc-800/80 rounded-xl shadow-xs space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <!-- Ícone do tipo de arquivo -->
                            <div 
                                class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 font-bold text-xs"
                                :class="{
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200/50 dark:border-indigo-800': ['STL', 'PLY', 'OBJ'].includes(item.extension),
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200/50 dark:border-emerald-800': ['DCM', 'DICOM'].includes(item.extension),
                                    'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700': !['STL', 'PLY', 'OBJ', 'DCM', 'DICOM'].includes(item.extension)
                                }"
                            >
                                <span x-text="item.extension"></span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 truncate" x-text="item.name"></p>
                                <div class="flex items-center flex-wrap gap-x-2 gap-y-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                    <!-- Informações de Tamanho e Redução -->
                                    <template x-if="item.formattedSize">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-medium text-zinc-700 dark:text-zinc-300" x-text="item.formattedSize"></span>
                                            <template x-if="item.compressionRatio > 0">
                                                <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300">
                                                    -<span x-text="item.compressionRatio"></span>% ZIP
                                                </span>
                                            </template>
                                            <span class="text-zinc-400 line-through text-[11px]" x-text="item.formattedOriginalSize"></span>
                                        </div>
                                    </template>
                                    <template x-if="!item.formattedSize">
                                        <span x-text="item.formattedOriginalSize"></span>
                                    </template>

                                    <!-- Status -->
                                    <template x-if="item.status === 'compressing'">
                                        <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                            <svg class="w-3 h-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            Compactando em ZIP...
                                        </span>
                                    </template>

                                    <template x-if="item.status === 'getting_url'">
                                        <span class="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400">
                                            <svg class="w-3 h-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            Obtendo assinatura...
                                        </span>
                                    </template>

                                    <template x-if="item.status === 'uploading'">
                                        <span class="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400">
                                            Enviando <span x-text="item.progress + '%'"></span>
                                        </span>
                                    </template>

                                    <template x-if="item.status === 'completed'">
                                        <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-medium">
                                            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                                            </svg>
                                            Enviado
                                        </span>
                                    </template>

                                    <template x-if="item.status === 'error'">
                                        <span class="text-rose-600 dark:text-rose-400 font-medium" x-text="item.errorMessage || 'Erro no envio'"></span>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Botão Remover / Retentar -->
                        <div class="flex items-center gap-1 shrink-0">
                            <template x-if="item.status === 'error' && item.file">
                                <button 
                                    type="button" 
                                    @click="retryUpload(item)"
                                    class="p-1.5 text-zinc-500 hover:text-indigo-600 dark:text-zinc-400 dark:hover:text-indigo-400 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-700/50 transition-colors"
                                    title="Tentar novamente"
                                >
                                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                    </svg>
                                </button>
                            </template>

                            <button 
                                type="button" 
                                @click="removeFile(item)"
                                class="p-1.5 text-zinc-400 hover:text-rose-600 dark:hover:text-rose-400 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-700/50 transition-colors"
                                title="Remover arquivo"
                            >
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Barra de Progresso quando estiver enviando -->
                    <template x-if="item.status === 'uploading'">
                        <div class="w-full bg-zinc-100 dark:bg-zinc-700 h-1.5 rounded-full overflow-hidden">
                            <div 
                                class="bg-indigo-600 dark:bg-indigo-500 h-1.5 rounded-full transition-all duration-150"
                                :style="'width: ' + item.progress + '%'"
                            ></div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </template>
</div>
