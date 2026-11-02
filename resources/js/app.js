import { registerFluxImageWebpUploader } from './alpine/fluxImageWebpUploader';
import { registerAvatarUpload } from './alpine/avatarUpload';
import { registerS3DirectUploader } from './alpine/s3DirectUploader';
import { registerStripeCardSetup } from './alpine/stripeCardSetup';
import imageZoom from './alpine/imageZoom';

registerFluxImageWebpUploader();
registerAvatarUpload();
registerS3DirectUploader();
registerStripeCardSetup();

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(imageZoom);
});