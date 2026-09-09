import { STORAGE_KEY } from './photo-upload-manager.js';

// app.js loads on every page, so guard on a marker that only exists on the
// success page — reaching it is only possible after a genuinely successful
// finalize (server-gated via the examination_success session flash), so
// clearing the photo-upload session credentials here is always safe.
if (document.getElementById('submission-number')) {
    sessionStorage.removeItem(STORAGE_KEY);
}
