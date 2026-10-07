import { registerFluxImageWebpUploader } from './alpine/fluxImageWebpUploader';
import imageZoom from './alpine/imageZoom';

registerFluxImageWebpUploader();

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(imageZoom);
});