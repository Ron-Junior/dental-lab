export function registerFluxImageWebpUploader() {
    const register = () => {
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            window.Alpine.data('fluxImageWebpUploader', (config) => ({
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

                    this.$wire.$refresh().then(() => {
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
                    }).catch(() => {
                        window.location.reload();
                    });
                },

                uploadToLivewire(file) {
                    this.statusText = 'Enviando...';

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
        }
    };

    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
}
