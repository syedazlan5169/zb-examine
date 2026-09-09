import { PhotoUploadManager } from './photo-upload-manager.js';

/**
 * Mounts the photo widget on the real Examination form and gates submission
 * on isReadyForSubmission(). Must run before examination-form.js's
 * double-submit protection (see app.js import order) so a blocked submission
 * never leaves the submit button stuck disabled.
 */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('examination-photos');
    const form = document.getElementById('examination-form');

    if (!root || !form) {
        return;
    }

    const config = root.dataset.config ? JSON.parse(root.dataset.config) : {};
    const photoManager = new PhotoUploadManager(root, config);

    const notReadyText = document.getElementById('examination-photos-not-ready');

    form.addEventListener('submit', (event) => {
        if (!photoManager.isReadyForSubmission()) {
            // preventDefault() alone does not stop the double-submit-protection
            // listener below from also running on this same submit event.
            event.preventDefault();
            event.stopImmediatePropagation();

            if (notReadyText) {
                notReadyText.hidden = false;
            }

            return;
        }

        if (notReadyText) {
            notReadyText.hidden = true;
        }

        form.elements.photo_upload_session_public_id.value = photoManager.session?.public_id ?? '';
        form.elements.photo_upload_token.value = photoManager.session?.token ?? '';
    });

    // A BFCache-restored create page must never present a stale, possibly
    // already-finalized session as submit-ready — force clean reinitialization.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });
});
