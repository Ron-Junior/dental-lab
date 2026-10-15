import { registerFluxImageWebpUploader } from './alpine/fluxImageWebpUploader';
import { registerAvatarUpload } from './alpine/avatarUpload';
import imageZoom from './alpine/imageZoom';

registerFluxImageWebpUploader();
registerAvatarUpload();

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(imageZoom);
});