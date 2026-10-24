// Fast CRC32 Table
const crcTable = new Uint32Array(256);
for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) {
        c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
    }
    crcTable[i] = c;
}

function calculateCrc32(bytes) {
    let crc = 0 ^ (-1);
    for (let i = 0; i < bytes.length; i++) {
        crc = (crc >>> 8) ^ crcTable[(crc ^ bytes[i]) & 0xFF];
    }
    return (crc ^ (-1)) >>> 0;
}

/**
 * Compresses a File object into a standard ZIP Blob using the browser's native CompressionStream.
 * Produces a standard PKZIP archive compatible with Windows, Mac, Linux, exocad, 3Shape, etc.
 *
 * @param {File} file
 * @returns {Promise<{ blob: Blob, zipName: string, originalSize: number, compressedSize: number }>}
 */
async function compressFileToZip(file) {
    const originalBytes = new Uint8Array(await file.arrayBuffer());
    const originalSize = originalBytes.byteLength;
    const filenameBytes = new TextEncoder().encode(file.name);
    const crc = calculateCrc32(originalBytes);

    // Compress using native deflate-raw stream
    const cs = new CompressionStream('deflate-raw');
    const writer = cs.writable.getWriter();
    writer.write(originalBytes);
    writer.close();

    const chunks = [];
    const reader = cs.readable.getReader();
    while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        chunks.push(value);
    }

    const totalCompressedLen = chunks.reduce((acc, c) => acc + c.byteLength, 0);
    const compressedBytes = new Uint8Array(totalCompressedLen);
    let offset = 0;
    for (const chunk of chunks) {
        compressedBytes.set(chunk, offset);
        offset += chunk.byteLength;
    }

    // DOS Date & Time
    const now = new Date();
    const dosTime = (now.getHours() << 11) | (now.getMinutes() << 5) | (now.getSeconds() >> 1);
    const dosDate = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();

    // 1. Local File Header (30 bytes)
    const localHeader = new Uint8Array(30);
    const localView = new DataView(localHeader.buffer);
    localView.setUint32(0, 0x04034b50, true);
    localView.setUint16(4, 20, true);
    localView.setUint16(6, 0x0800, true); // UTF-8 filename flag
    localView.setUint16(8, 8, true); // Deflate
    localView.setUint16(10, dosTime, true);
    localView.setUint16(12, dosDate, true);
    localView.setUint32(14, crc, true);
    localView.setUint32(18, compressedBytes.byteLength, true);
    localView.setUint32(22, originalSize, true);
    localView.setUint16(26, filenameBytes.byteLength, true);
    localView.setUint16(28, 0, true);

    // 2. Central Directory Header (46 bytes)
    const cdHeader = new Uint8Array(46);
    const cdView = new DataView(cdHeader.buffer);
    cdView.setUint32(0, 0x02014b50, true);
    cdView.setUint16(4, 20, true);
    cdView.setUint16(6, 20, true);
    cdView.setUint16(8, 0x0800, true);
    cdView.setUint16(10, 8, true);
    cdView.setUint16(12, dosTime, true);
    cdView.setUint16(14, dosDate, true);
    cdView.setUint32(16, crc, true);
    cdView.setUint32(20, compressedBytes.byteLength, true);
    cdView.setUint32(24, originalSize, true);
    cdView.setUint16(28, filenameBytes.byteLength, true);
    cdView.setUint16(30, 0, true);
    cdView.setUint16(32, 0, true);
    cdView.setUint16(34, 0, true);
    cdView.setUint16(36, 0, true);
    cdView.setUint32(38, 0, true);
    cdView.setUint32(42, 0, true); // Offset 0

    const localRecordLength = 30 + filenameBytes.byteLength + compressedBytes.byteLength;
    const cdLength = 46 + filenameBytes.byteLength;

    // 3. End of Central Directory Record (22 bytes)
    const eocd = new Uint8Array(22);
    const eocdView = new DataView(eocd.buffer);
    eocdView.setUint32(0, 0x06054b50, true);
    eocdView.setUint16(4, 0, true);
    eocdView.setUint16(6, 0, true);
    eocdView.setUint16(8, 1, true);
    eocdView.setUint16(10, 1, true);
    eocdView.setUint32(12, cdLength, true);
    eocdView.setUint32(16, localRecordLength, true);
    eocdView.setUint16(20, 0, true);

    const zipBlob = new Blob([
        localHeader,
        filenameBytes,
        compressedBytes,
        cdHeader,
        filenameBytes,
        eocd
    ], { type: 'application/zip' });

    return {
        blob: zipBlob,
        zipName: `${file.name}.zip`,
        originalSize: originalSize,
        compressedSize: zipBlob.size
    };
}

export function registerS3DirectUploader() {
    const register = () => {
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            window.Alpine.data('s3DirectUploader', (config = {}) => ({
                isDragging: false,
                isProcessing: false,
                files: [],
                allowedExtensions: ['stl', 'ply', 'obj', 'dcm', 'dicom'],
                modelName: config.modelName || 'uploadedFiles',

                init() {
                    const currentFiles = this.$wire.get(this.modelName) || [];
                    if (Array.isArray(currentFiles) && currentFiles.length > 0) {
                        this.files = currentFiles.map(f => ({
                            id: 'file_' + Math.random().toString(36).substring(2, 9),
                            name: f.name,
                            zipName: f.zip_name || (f.name + '.zip'),
                            size: f.size || 0,
                            originalSize: f.original_size || f.size || 0,
                            formattedSize: this.formatBytes(f.size || 0),
                            formattedOriginalSize: this.formatBytes(f.original_size || f.size || 0),
                            compressionRatio: f.original_size && f.size && f.original_size > f.size
                                ? Math.round((1 - (f.size / f.original_size)) * 100)
                                : 0,
                            extension: (f.extension || this.getExtension(f.name)).toUpperCase(),
                            status: 'completed',
                            progress: 100,
                            path: f.path,
                            disk: f.disk || 's3',
                            errorMessage: ''
                        }));
                    }

                    this.$watch('$wire.' + this.modelName, (newVal) => {
                        if (!newVal || newVal.length === 0) {
                            if (this.files.some(f => f.status === 'completed')) {
                                this.files = [];
                            }
                        }
                    });
                },

                handleDrop(event) {
                    this.isDragging = false;
                    const droppedFiles = event.dataTransfer ? event.dataTransfer.files : [];
                    if (droppedFiles && droppedFiles.length > 0) {
                        this.processFiles(droppedFiles);
                    }
                },

                handleFileSelect(event) {
                    const selectedFiles = event.target ? event.target.files : [];
                    if (selectedFiles && selectedFiles.length > 0) {
                        this.processFiles(selectedFiles);
                    }
                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.value = '';
                    }
                },

                getExtension(filename) {
                    if (!filename) return '';
                    const parts = filename.split('.');
                    return parts.length > 1 ? parts.pop().toLowerCase() : '';
                },

                formatBytes(bytes) {
                    if (!bytes || bytes === 0) return '0 B';
                    const k = 1024;
                    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                },

                processFiles(fileList) {
                    Array.from(fileList).forEach(file => {
                        const ext = this.getExtension(file.name);
                        if (!this.allowedExtensions.includes(ext)) {
                            this.files.push({
                                id: 'file_' + Math.random().toString(36).substring(2, 9),
                                name: file.name,
                                originalSize: file.size,
                                formattedOriginalSize: this.formatBytes(file.size),
                                extension: ext.toUpperCase() || 'N/A',
                                status: 'error',
                                progress: 0,
                                errorMessage: `Formato .${ext} não suportado. Aceitos: STL, PLY, OBJ, DCM/DICOM.`
                            });
                            return;
                        }

                        const fileItem = {
                            id: 'file_' + Math.random().toString(36).substring(2, 9),
                            file: file,
                            name: file.name,
                            zipName: `${file.name}.zip`,
                            originalSize: file.size,
                            compressedSize: 0,
                            compressionRatio: 0,
                            formattedOriginalSize: this.formatBytes(file.size),
                            formattedSize: '',
                            extension: ext.toUpperCase(),
                            status: 'compressing',
                            progress: 0,
                            path: null,
                            disk: 's3',
                            errorMessage: '',
                            xhr: null
                        };

                        this.files.push(fileItem);
                        this.compressAndUpload(fileItem);
                    });
                },

                async compressAndUpload(fileItem) {
                    this.updateProcessingState();
                    try {
                        fileItem.status = 'compressing';
                        fileItem.errorMessage = '';

                        // 1. Compress to ZIP in the browser
                        const zipResult = await compressFileToZip(fileItem.file);
                        fileItem.zipBlob = zipResult.blob;
                        fileItem.zipName = zipResult.zipName;
                        fileItem.compressedSize = zipResult.compressedSize;
                        fileItem.formattedSize = this.formatBytes(zipResult.compressedSize);
                        fileItem.compressionRatio = fileItem.originalSize > zipResult.compressedSize
                            ? Math.round((1 - (zipResult.compressedSize / fileItem.originalSize)) * 100)
                            : 0;

                        // 2. Request Presigned Upload URL from Livewire
                        fileItem.status = 'getting_url';
                        const presignedData = await this.$wire.getPreSignedUploadUrl(
                            fileItem.zipName,
                            'application/zip',
                            fileItem.compressedSize
                        );

                        if (!presignedData || !presignedData.url) {
                            throw new Error('Não foi possível gerar a URL pré-assinada.');
                        }

                        fileItem.path = presignedData.path;
                        fileItem.disk = presignedData.disk || 's3';
                        fileItem.status = 'uploading';

                        // 3. Upload the compressed ZIP Blob directly to S3
                        const xhr = new XMLHttpRequest();
                        fileItem.xhr = xhr;

                        xhr.open('PUT', presignedData.url, true);

                        if (presignedData.headers) {
                            for (const [key, val] of Object.entries(presignedData.headers)) {
                                if (key.toLowerCase() !== 'host') {
                                    xhr.setRequestHeader(key, Array.isArray(val) ? val[0] : val);
                                }
                            }
                        }

                        if (!presignedData.headers || !presignedData.headers['Content-Type']) {
                            xhr.setRequestHeader('Content-Type', 'application/zip');
                        }

                        xhr.upload.onprogress = (e) => {
                            if (e.lengthComputable) {
                                fileItem.progress = Math.round((e.loaded / e.total) * 100);
                            }
                        };

                        xhr.onload = () => {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                fileItem.status = 'completed';
                                fileItem.progress = 100;
                                fileItem.xhr = null;
                                this.syncWithLivewire();
                            } else {
                                fileItem.status = 'error';
                                fileItem.errorMessage = `Falha no envio (HTTP ${xhr.status})`;
                                fileItem.xhr = null;
                            }
                            this.updateProcessingState();
                        };

                        xhr.onerror = () => {
                            fileItem.status = 'error';
                            fileItem.errorMessage = 'Erro de rede durante o upload.';
                            fileItem.xhr = null;
                            this.updateProcessingState();
                        };

                        xhr.onabort = () => {
                            fileItem.status = 'canceled';
                            this.updateProcessingState();
                        };

                        xhr.send(fileItem.zipBlob);
                    } catch (error) {
                        fileItem.status = 'error';
                        fileItem.errorMessage = error.message || 'Erro ao compactar ou preparar upload.';
                        this.updateProcessingState();
                    }
                },

                retryUpload(fileItem) {
                    if (fileItem.file) {
                        this.compressAndUpload(fileItem);
                    }
                },

                removeFile(fileItem) {
                    if (fileItem.xhr) {
                        fileItem.xhr.abort();
                    }

                    const index = this.files.findIndex(f => f.id === fileItem.id);
                    if (index !== -1) {
                        this.files.splice(index, 1);
                    }

                    this.syncWithLivewire();
                    this.updateProcessingState();
                },

                syncWithLivewire() {
                    const completed = this.files
                        .filter(f => f.status === 'completed' && f.path)
                        .map(f => ({
                            name: f.name,
                            zip_name: f.zipName,
                            path: f.path,
                            size: f.compressedSize,
                            original_size: f.originalSize,
                            extension: f.extension ? f.extension.toLowerCase() : '',
                            disk: f.disk || 's3',
                            mime_type: 'application/zip'
                        }));

                    this.$wire.set(this.modelName, completed);
                },

                updateProcessingState() {
                    this.isProcessing = this.files.some(f => 
                        f.status === 'compressing' || 
                        f.status === 'getting_url' || 
                        f.status === 'uploading'
                    );
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
