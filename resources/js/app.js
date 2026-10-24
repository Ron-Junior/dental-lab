import { registerFluxImageWebpUploader } from './alpine/fluxImageWebpUploader';
import { registerAvatarUpload } from './alpine/avatarUpload';
import { registerS3DirectUploader } from './alpine/s3DirectUploader';
import imageZoom from './alpine/imageZoom';

registerFluxImageWebpUploader();
registerAvatarUpload();
registerS3DirectUploader();

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(imageZoom);
});