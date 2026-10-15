export function registerAvatarUpload() {
    const register = () => {
        if (!window.Alpine || typeof window.Alpine.data !== 'function') {
            return;
        }

        window.Alpine.data('avatarUpload', (config) => ({
            modelName: config.modelName,
            currentUrl: config.currentUrl,
            userName: config.userName,
            width: config.width,
            height: config.height,
            quality: config.quality,
            previewUrl: null,
            fileName: '',
            isProcessing: false,
            statusText: '',
            errorMessage: '',

            get initials() {
                return this.userName
                    .split(/\s+/)
                    .filter(Boolean)
                    .slice(0, 2)
                    .map((part) => part[0])
                    .join('')
                    .toUpperCase();
            },

            selectFile(file) {
                if (!file) {
                    return;
                }

                if (file.size > 10 * 1024 * 1024) {
                    this.fail('A imagem deve ter no máximo 10 MB.');
                    return;
                }

                this.errorMessage = '';
                this.isProcessing = true;
                this.statusText = 'Convertendo imagem...';
                this.fileName = file.name.replace(/\.[^/.]+$/, '') + '.webp';

                const reader = new FileReader();

                reader.onerror = () => {
                    this.fail('Não foi possível ler a imagem selecionada.');
                };

                reader.onload = (event) => {
                    const image = new Image();

                    image.onerror = () => {
                        this.fail('O arquivo selecionado não é uma imagem válida.');
                    };

                    image.onload = () => this.convertAndUpload(image);
                    image.src = event.target.result;
                };

                reader.readAsDataURL(file);
            },

            convertAndUpload(image) {
                const canvas = document.createElement('canvas');
                canvas.width = this.width;
                canvas.height = this.height;

                const context = canvas.getContext('2d');

                if (!context) {
                    this.fail('Não foi possível processar a imagem neste navegador.');
                    return;
                }

                const sourceAspect = image.width / image.height;
                const targetAspect = this.width / this.height;
                let sourceX = 0;
                let sourceY = 0;
                let sourceWidth = image.width;
                let sourceHeight = image.height;

                if (sourceAspect > targetAspect) {
                    sourceWidth = image.height * targetAspect;
                    sourceX = (image.width - sourceWidth) / 2;
                } else {
                    sourceHeight = image.width / targetAspect;
                    sourceY = (image.height - sourceHeight) / 2;
                }

                context.drawImage(
                    image,
                    sourceX,
                    sourceY,
                    sourceWidth,
                    sourceHeight,
                    0,
                    0,
                    this.width,
                    this.height,
                );

                canvas.toBlob((blob) => {
                    if (!blob) {
                        this.fail('Não foi possível converter a imagem para WebP.');
                        return;
                    }

                    const webpFile = new File([blob], this.fileName, { type: 'image/webp' });
                    const previewUrl = URL.createObjectURL(blob);

                    if (this.previewUrl) {
                        URL.revokeObjectURL(this.previewUrl);
                    }

                    this.previewUrl = previewUrl;
                    this.uploadToLivewire(webpFile);
                }, 'image/webp', this.quality);
            },

            uploadToLivewire(file) {
                this.statusText = 'Enviando para o armazenamento...';

                this.$wire.upload(
                    this.modelName,
                    file,
                    () => {
                        this.isProcessing = false;
                        this.statusText = 'Foto pronta para salvar.';
                    },
                    () => {
                        this.fail('Falha ao enviar a foto. Tente novamente.');
                    },
                    (event) => {
                        this.statusText = `Enviando... ${event.detail.progress}%`;
                    },
                );
            },

            setCurrentUrl(url) {
                if (url) {
                    this.currentUrl = url;
                }

                if (this.previewUrl) {
                    URL.revokeObjectURL(this.previewUrl);
                }

                this.previewUrl = null;
                this.fileName = '';
                this.statusText = '';
                this.$refs.fileInput.value = '';
            },

            fail(message) {
                this.isProcessing = false;
                this.statusText = '';
                this.errorMessage = message;
            },
        }));
    };

    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
}
