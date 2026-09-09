import { PhotoUploadManager } from './photo-upload-manager.js';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('photo-upload-workbench');
    if (!root) {
        return;
    }

    const config = root.dataset.config ? JSON.parse(root.dataset.config) : {};
    new PhotoUploadManager(root, config);
});
