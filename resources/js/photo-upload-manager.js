import { allocatePhoto, completePhoto, createSession, deletePhoto, fetchPhotoPreview, readSession, uploadPhoto } from './photo-upload-api.js';
import { optimizeImage } from './image-optimizer.js';

export const STORAGE_KEY = 'zb-examine.photo-upload-session.v1';
const MAX_PHOTOS = 10;
const MAX_CONCURRENT = 2;

function stateLabel(key, config = {}) {
    return config.messages?.state?.[key] || key;
}

export class PhotoUploadManager {
    constructor(root, config = {}) {
        this.root = root;
        this.config = config;
        this.session = null;
        this.photos = [];
        this.activeJobs = 0;
        this.jobQueue = [];
        this.sessionPromise = null;
        this.pendingMessage = '';
        this.slowMode = false;
        this.bindInputs();
        this.attachEvents();
        this.resumeSessionIfAvailable();
        this.render();
    }

    bindInputs() {
        this.cameraInput = this.root.querySelector('[data-role="camera-input"]');
        this.libraryInput = this.root.querySelector('[data-role="library-input"]');
        this.list = this.root.querySelector('[data-role="photo-list"]');
        this.statusText = this.root.querySelector('[data-role="status-text"]');
        this.summary = this.root.querySelector('[data-role="summary"]');
        this.slowModeToggle = this.root.querySelector('[data-role="slow-mode-toggle"]');
    }

    attachEvents() {
        this.root.addEventListener('click', (event) => {
            const actionTarget = event.target.closest('[data-action]');
            if (!actionTarget) {
                return;
            }

            const { action, photoId } = actionTarget.dataset;

            if (action === 'camera-trigger') {
                this.cameraInput?.click();
                return;
            }

            if (action === 'library-trigger') {
                this.libraryInput?.click();
                return;
            }

            if (action === 'remove-photo') {
                const photo = this.findPhoto(photoId);
                if (photo) {
                    this.removePhoto(photo);
                }
                return;
            }

            if (action === 'retry-photo') {
                const photo = this.findPhoto(photoId);
                if (photo) {
                    this.retryPhoto(photo);
                }
                return;
            }

            if (action === 'retry-remove') {
                const photo = this.findPhoto(photoId);
                if (photo) {
                    this.retryRemovePhoto(photo);
                }
                return;
            }

            if (action === 'recover-photo') {
                const photo = this.findPhoto(photoId);
                if (photo) {
                    this.recoverPendingPhoto(photo);
                }
            }
        });

        this.cameraInput?.addEventListener('change', (event) => {
            this.handleSelections(event.target.files, 'camera');
            event.target.value = '';
        });

        this.libraryInput?.addEventListener('change', (event) => {
            this.handleSelections(event.target.files, 'library');
            event.target.value = '';
        });

        this.slowModeToggle?.addEventListener('change', (event) => {
            this.slowMode = Boolean(event.target.checked);
        });
    }

    // Dev-only artificial delay so a human can exercise removal races on fast local uploads.
    async devDelay() {
        if (!this.slowMode) {
            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 3000));
    }

    findPhoto(photoId) {
        return this.photos.find((photo) => photo.id === photoId) || null;
    }

    isCurrent(photo, generation) {
        return Boolean(photo)
            && Number.isInteger(generation)
            && photo.generation === generation
            && !photo.cancelled
            && this.photos.some((item) => item.id === photo.id);
    }

    beginAttempt(photo) {
        if (!photo) {
            return null;
        }

        if (photo.abortController && !photo.abortController.signal.aborted) {
            photo.abortController.abort();
        }

        photo.generation = (photo.generation ?? 0) + 1;
        photo.cancelled = false;
        photo.abortController = new AbortController();

        return photo.generation;
    }

    revokePreview(photo) {
        if (!photo || !photo.previewUrl) {
            return;
        }

        URL.revokeObjectURL(photo.previewUrl);
        photo.previewUrl = '';
    }

    // The only path allowed to change photo.previewUrl for a still-current photo:
    // revokes whatever this photo previously owned, then adopts the new URL.
    setPreview(photo, url) {
        if (photo.previewUrl && photo.previewUrl !== url) {
            URL.revokeObjectURL(photo.previewUrl);
        }

        photo.previewUrl = url;
    }

    async cleanupAllocatedPhoto(publicId) {
        if (!publicId || !this.session) {
            return;
        }

        try {
            await deletePhoto(this.session.public_id, this.session.token, publicId, { signal: new AbortController().signal });
        } catch {
            // Best-effort cleanup for stale (non-removal) generations only; explicit user removal never goes through here.
        }
    }

    // Single authoritative path for deleting a backend row tied to an explicit, visible removal intent.
    async finalizeRemoval(photo) {
        photo.state = 'removing';
        this.render();

        try {
            await deletePhoto(this.session.public_id, this.session.token, photo.backendPublicId, { signal: new AbortController().signal });
        } catch (error) {
            if (error && error.code === 'invalid_session') {
                this.clearSessionStorage();
            }

            photo.state = 'remove_failed';
            this.render();
            return;
        }

        this.revokePreview(photo);
        this.photos = this.photos.filter((item) => item.id !== photo.id);
        this.render();
    }

    isReadyForSubmission() {
        return this.photos.length > 0 && this.photos.every((photo) => photo.state === 'uploaded');
    }

    setMessage(message = '') {
        this.pendingMessage = message;
        this.renderStatus();
    }

    renderStatus() {
        if (!this.statusText) {
            return;
        }

        this.statusText.textContent = this.pendingMessage || this.summaryText();
        this.statusText.className = this.pendingMessage
            ? 'min-h-6 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800'
            : 'min-h-6 text-sm text-gray-600';
    }

    summaryText() {
        const visibleCount = this.photos.filter((photo) => photo.state !== 'removed').length;
        return `${visibleCount} / ${MAX_PHOTOS} ${this.config.messages?.countLabel || 'photos'}`;
    }

    render() {
        if (!this.list) {
            return;
        }

        const cards = this.photos.map((photo) => this.renderPhotoCard(photo)).join('');
        this.list.innerHTML = cards || `<p class="text-sm text-gray-500">${this.config.messages?.empty || 'No photos yet.'}</p>`;

        if (this.summary) {
            this.summary.textContent = this.summaryText();
        }

        this.renderStatus();
    }

    renderPhotoCard(photo) {
        const preview = photo.previewUrl
            ? `<img src="${photo.previewUrl}" alt="Photo preview" class="h-28 w-28 rounded-lg object-cover" />`
            : `<div class="flex h-28 w-28 items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-100 text-[10px] font-semibold uppercase tracking-wide text-gray-500">${this.config.messages?.noPreview || 'No Preview'}</div>`;

        const detail = [];
        if (photo.originalWidth && photo.originalHeight) {
            detail.push(`${photo.originalWidth} × ${photo.originalHeight}`);
        }
        if (photo.originalBytes) {
            detail.push(this.humanBytes(photo.originalBytes));
        }

        const optimizedDetail = [];
        if (photo.optimizedWidth && photo.optimizedHeight) {
            optimizedDetail.push(`${photo.optimizedWidth} × ${photo.optimizedHeight}`);
        }
        if (photo.optimizedBytes) {
            optimizedDetail.push(this.humanBytes(photo.optimizedBytes));
        }
        if (photo.stage) {
            optimizedDetail.push(`${this.config.messages?.stageLabel || 'Stage'} ${photo.stage}`);
        }

        const actions = this.renderActions(photo);
        const stateText = stateLabel(photo.state, this.config) || photo.state;
        const showDiagnostics = this.config.showDiagnostics !== false;

        return `
            <div class="flex gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                <div class="flex-none">${preview}</div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">${this.config.messages?.photoLabel || 'Photo'} ${photo.displayNumber || 1}</p>
                            <p class="text-xs text-gray-600">${stateText}</p>
                        </div>
                        <button type="button" data-action="remove-photo" data-photo-id="${photo.id}" class="rounded-md border border-red-200 bg-red-50 px-2 py-1 text-xs font-medium text-red-700">${this.config.messages?.remove || 'Remove'}</button>
                    </div>
                    ${showDiagnostics ? `
                    <div class="mt-2 space-y-1 text-[11px] text-gray-600">
                        ${detail.length ? `<div><span class="font-semibold">${this.config.messages?.originalLabel || 'Original'}:</span> ${detail.join(' · ')}</div>` : ''}
                        ${optimizedDetail.length ? `<div><span class="font-semibold">${this.config.messages?.optimizedLabel || 'Optimized'}:</span> ${optimizedDetail.join(' · ')}</div>` : ''}
                        ${!detail.length && !optimizedDetail.length ? '<div>Waiting</div>' : ''}
                    </div>
                    ` : ''}
                    ${actions}
                </div>
            </div>
        `;
    }

    renderActions(photo) {
        if (photo.state === 'uploaded') {
            return `<div class="mt-3 text-xs text-green-700">${this.config.messages?.uploaded || 'Uploaded'}</div>`;
        }

        if (photo.state === 'failed') {
            return `
                <div class="mt-3 flex gap-2">
                    <button type="button" data-action="retry-photo" data-photo-id="${photo.id}" class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white">${this.config.messages?.retry || 'Retry'}</button>
                    <button type="button" data-action="remove-photo" data-photo-id="${photo.id}" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">${this.config.messages?.remove || 'Remove'}</button>
                </div>
            `;
        }

        if (photo.state === 'remove_failed') {
            return `
                <div class="mt-3 flex gap-2">
                    <button type="button" data-action="retry-remove" data-photo-id="${photo.id}" class="rounded-md bg-red-700 px-3 py-2 text-xs font-medium text-white">${this.config.messages?.retryRemove || 'Retry Remove'}</button>
                    <button type="button" data-action="remove-photo" data-photo-id="${photo.id}" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">${this.config.messages?.remove || 'Remove'}</button>
                </div>
            `;
        }

        if (photo.state === 'retry_cleanup_failed') {
            return `
                <div class="mt-3 flex gap-2">
                    <button type="button" data-action="retry-photo" data-photo-id="${photo.id}" class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white">${this.config.messages?.retry || 'Retry'}</button>
                    <button type="button" data-action="remove-photo" data-photo-id="${photo.id}" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">${this.config.messages?.remove || 'Remove'}</button>
                </div>
            `;
        }

        if (photo.state === 'needs_reselection') {
            return `
                <div class="mt-3 flex gap-2">
                    <button type="button" data-action="recover-photo" data-photo-id="${photo.id}" class="rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white">${this.config.messages?.recover || 'Complete / Re-check'}</button>
                    <button type="button" data-action="remove-photo" data-photo-id="${photo.id}" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700">${this.config.messages?.remove || 'Remove'}</button>
                </div>
            `;
        }

        if (photo.state === 'processing' || photo.state === 'allocating' || photo.state === 'uploading' || photo.state === 'completing' || photo.state === 'removing' || photo.state === 'retry_cleanup') {
            return `<div class="mt-3 text-xs text-gray-600">${this.config.messages?.working || 'Working…'}</div>`;
        }

        return `<div class="mt-3 text-xs text-gray-600">${this.config.messages?.queued || 'Queued'}</div>`;
    }

    humanBytes(size) {
        if (!Number.isFinite(size) || size <= 0) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB'];
        let value = size;
        let unitIndex = 0;

        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }

        return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
    }

    handleSelections(files, source) {
        if (!files || !files.length) {
            return;
        }

        const selected = Array.from(files);
        const remaining = MAX_PHOTOS - this.photos.length;

        if (remaining <= 0) {
            this.setMessage(this.config.messages?.maxReached || 'Maximum 10 photos reached.');
            return;
        }

        const accepted = selected.slice(0, remaining);
        if (accepted.length < selected.length) {
            this.setMessage(this.config.messages?.extrasIgnored || 'Some photos were not added because the limit has been reached.');
        }

        for (const file of accepted) {
            this.createLocalPhoto(file, source);
        }
    }

    createLocalPhoto(file, source) {
        const photo = {
            id: `photo-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            displayNumber: this.photos.length + 1,
            state: 'queued',
            file,
            previewUrl: '',
            originalWidth: null,
            originalHeight: null,
            optimizedWidth: null,
            optimizedHeight: null,
            originalBytes: null,
            optimizedBytes: null,
            stage: null,
            source,
            backendPublicId: null,
            sessionPublicId: null,
            signal: null,
            generation: 0,
            cancelled: false,
            removalRequested: false,
            allocationDispatched: false,
            abortController: null,
            error: null,
        };

        this.photos.push(photo);
        this.render();
        this.jobQueue.push(photo);
        this.processQueue();
    }

    processQueue() {
        while (this.activeJobs < MAX_CONCURRENT && this.jobQueue.length) {
            const photo = this.jobQueue.shift();
            if (!photo || photo.state === 'removed') {
                continue;
            }

            this.activeJobs += 1;
            this.processPhoto(photo).finally(() => {
                this.activeJobs -= 1;
                this.processQueue();
            });
        }
    }

    async processPhoto(photo) {
        if (!photo || photo.state === 'removed') {
            return;
        }

        const generation = this.beginAttempt(photo);

        try {
            photo.state = 'processing';
            photo.error = null;
            this.render();

            const optimized = await optimizeImage(photo.file, {
                signal: photo.abortController?.signal,
            });

            if (!this.isCurrent(photo, generation)) {
                return;
            }

            photo.originalWidth = optimized.originalWidth;
            photo.originalHeight = optimized.originalHeight;
            photo.optimizedWidth = optimized.width;
            photo.optimizedHeight = optimized.height;
            photo.originalBytes = optimized.originalBytes;
            photo.optimizedBytes = optimized.outputBytes;
            photo.stage = optimized.stage;
            this.setPreview(photo, URL.createObjectURL(optimized.blob));
            photo.state = 'queued';
            this.render();

            await this.ensureSession();

            if (!this.isCurrent(photo, generation)) {
                return;
            }

            photo.state = 'allocating';
            photo.allocationDispatched = false;
            this.render();
            await this.devDelay();

            if (!this.isCurrent(photo, generation)) {
                // Cancelled before the request was ever sent: nothing to clean up server-side.
                return;
            }

            photo.allocationDispatched = true;
            const allocated = await allocatePhoto(this.session.public_id, this.session.token, { signal: photo.abortController?.signal });

            if (photo.removalRequested) {
                // Exception to the stale-generation rule: the id must be captured so it can be cleaned up.
                photo.backendPublicId = allocated.public_id;
                photo.sessionPublicId = this.session.public_id;
                await this.finalizeRemoval(photo);
                return;
            }

            if (!this.isCurrent(photo, generation)) {
                await this.cleanupAllocatedPhoto(allocated?.public_id);
                return;
            }

            photo.backendPublicId = allocated.public_id;
            photo.sessionPublicId = this.session.public_id;
            photo.state = 'uploading';
            this.render();
            await this.devDelay();

            if (!this.isCurrent(photo, generation)) {
                return;
            }

            const optimizedFile = new File([optimized.blob], 'photo.jpg', { type: 'image/jpeg' });
            let completed;

            try {
                await uploadPhoto(this.session.public_id, this.session.token, allocated.public_id, optimizedFile, {
                    signal: photo.abortController?.signal,
                    uploadMode: allocated.upload_mode,
                });
            } catch (error) {
                if (allocated.upload_mode !== 'direct' || !error?.directPutUncertain || photo.removalRequested) {
                    throw error;
                }

                photo.state = 'completing';
                this.render();
                completed = await this.completeAfterUncertainDirectPut(photo, optimizedFile);
            }

            if (!this.isCurrent(photo, generation)) {
                await this.cleanupAllocatedPhoto(photo.backendPublicId);
                return;
            }

            photo.state = 'completing';
            this.render();
            await this.devDelay();

            if (!this.isCurrent(photo, generation)) {
                return;
            }

            completed ??= await completePhoto(this.session.public_id, this.session.token, allocated.public_id, { signal: photo.abortController?.signal });

            if (!this.isCurrent(photo, generation)) {
                await this.cleanupAllocatedPhoto(photo.backendPublicId);
                return;
            }

            photo.state = completed.verified ? 'uploaded' : 'failed';
            photo.optimizedBytes = completed.file_size || photo.optimizedBytes;
            photo.optimizedWidth = completed.width || photo.optimizedWidth;
            photo.optimizedHeight = completed.height || photo.optimizedHeight;
            this.render();
        } catch (error) {
            if (photo.removalRequested && !photo.backendPublicId) {
                // Allocation outcome is ambiguous (network failure before a public_id was known).
                // Never resume upload/complete and never resurrect into a normal retry state.
                if (!error || error.name !== 'AbortError') {
                    photo.state = 'remove_failed';
                    this.render();
                }
                return;
            }

            if (!this.isCurrent(photo, generation)) {
                return;
            }

            await this.handleError(photo, error);
        } finally {
            // Preview ownership is handled by setPreview()/revokePreview() at the point of
            // assignment or definitive removal — never blanket-revoked here.
            if (this.isCurrent(photo, generation)) {
                this.render();
            }
        }
    }

    async completeAfterUncertainDirectPut(photo, optimizedFile) {
        try {
            return await completePhoto(this.session.public_id, this.session.token, photo.backendPublicId, {
                signal: photo.abortController?.signal,
            });
        } catch (error) {
            if (!['upload_not_ready', 'source_changed'].includes(error?.code)) {
                throw error;
            }

            // uploadPhoto() obtains a fresh authorization; the failed signed URL is never reused.
            await uploadPhoto(this.session.public_id, this.session.token, photo.backendPublicId, optimizedFile, {
                signal: photo.abortController?.signal,
                uploadMode: 'direct',
            });

            return completePhoto(this.session.public_id, this.session.token, photo.backendPublicId, {
                signal: photo.abortController?.signal,
            });
        }
    }


    async handleError(photo, error) {
        if (!photo || photo.state === 'removed' || !this.isCurrent(photo, photo.generation)) {
            return;
        }

        if (error && error.name === 'AbortError') {
            if (!this.photos.some((item) => item.id === photo.id)) {
                return;
            }
            photo.state = 'failed';
            this.render();
            return;
        }

        const code = (error && error.code) || 'request_failed';

        if (code === 'invalid_session' || code === 'session_expired') {
            this.clearSessionStorage();
            photo.state = 'failed';
            this.setMessage(this.config.messages?.sessionReset || 'Photo upload session expired. Please pick a photo again.');
            return;
        }

        if (code === 'photo_state_conflict' && photo.backendPublicId) {
            try {
                const completed = await completePhoto(this.session.public_id, this.session.token, photo.backendPublicId, { signal: photo.abortController?.signal });
                photo.state = completed.verified ? 'uploaded' : 'failed';
                this.render();
                return;
            } catch (completeError) {
                photo.state = 'failed';
                this.setMessage(this.config.messages?.retryLater || 'The photo could not be completed. Please retry later.');
                return;
            }
        }

        if (code === 'upload_not_ready' && photo.backendPublicId) {
            photo.state = 'needs_reselection';
            return;
        }

        photo.state = 'failed';
        this.setMessage((error && error.message) || this.config.messages?.genericError || 'Upload failed.');
    }

    async ensureSession() {
        if (this.session) {
            return this.session;
        }

        if (this.sessionPromise) {
            return this.sessionPromise;
        }

        this.sessionPromise = createSession().then((response) => {
            this.session = {
                public_id: response.public_id,
                token: response.token,
                expires_at: response.expires_at,
            };
            this.persistSession(this.session);
            this.setMessage('');
            return this.session;
        }).catch((error) => {
            this.sessionPromise = null;
            throw error;
        });

        try {
            return await this.sessionPromise;
        } finally {
            this.sessionPromise = null;
        }
    }

    persistSession(session) {
        if (!session) {
            sessionStorage.removeItem(STORAGE_KEY);
            return;
        }

        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
            public_id: session.public_id,
            token: session.token,
            expires_at: session.expires_at,
        }));
    }

    readSessionStorage() {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch {
            return null;
        }
    }

    clearSessionStorage() {
        sessionStorage.removeItem(STORAGE_KEY);
        this.session = null;
    }

    async resumeSessionIfAvailable() {
        const storedSession = this.readSessionStorage();
        if (!storedSession || !storedSession.public_id || !storedSession.token) {
            return;
        }

        try {
            const response = await readSession(storedSession.public_id, storedSession.token);

            if (response.finalized) {
                // Server already claimed this session for an Examination: never present it as submit-ready again.
                this.clearSessionStorage();
                this.setMessage(this.config.messages?.sessionFinalized || 'This photo session has already been used.');
                return;
            }

            this.session = {
                public_id: response.public_id,
                token: storedSession.token,
                expires_at: response.expires_at,
            };
            this.persistSession(this.session);

            if (!Array.isArray(response.photos)) {
                return;
            }

            for (const photo of response.photos) {
                const recovered = {
                    id: `resume-${photo.public_id}`,
                    displayNumber: photo.display_order || this.photos.length + 1,
                    state: photo.verified ? 'uploaded' : 'needs_reselection',
                    file: null,
                    previewUrl: '',
                    originalWidth: null,
                    originalHeight: null,
                    optimizedWidth: photo.width || null,
                    optimizedHeight: photo.height || null,
                    originalBytes: null,
                    optimizedBytes: photo.file_size || null,
                    stage: null,
                    backendPublicId: photo.public_id,
                    sessionPublicId: response.public_id,
                    source: 'resume',
                    generation: 0,
                    cancelled: false,
                    abortController: null,
                };

                this.photos.push(recovered);

                if (recovered.state === 'uploaded') {
                    await this.restorePreview(recovered);
                }
            }

            this.render();
        } catch (error) {
            this.clearSessionStorage();
            this.setMessage(this.config.messages?.sessionReset || 'Your photo session could not be resumed.');
        }
    }

    async restorePreview(photo) {
        try {
            const blob = await fetchPhotoPreview(this.session.public_id, this.session.token, photo.backendPublicId);
            this.setPreview(photo, URL.createObjectURL(blob));
            this.render();
        } catch {
            // The upload remains submit-ready; the preview can be retried by reloading the page.
        }
    }

    async retryPhoto(photo) {
        if (!photo) {
            return;
        }

        if (photo.state === 'remove_failed') {
            await this.retryRemovePhoto(photo);
            return;
        }

        if (photo.state === 'retry_cleanup_failed') {
            // Locked invariant: never allocate a replacement while an old row is unresolved.
            await this.replaceUnusableBackendPhoto(photo);
            return;
        }

        if (photo.backendPublicId) {
            // Write-once-aware: an existing backend row must always be reconciled first,
            // regardless of whether we still hold the local File for this photo.
            await this.retryExistingBackendPhoto(photo);
            return;
        }

        if (photo.file && (photo.state === 'failed' || photo.state === 'needs_reselection')) {
            this.requeueForFreshUpload(photo);
        }
    }

    requeueForFreshUpload(photo) {
        this.jobQueue = this.jobQueue.filter((item) => item.id !== photo.id);
        this.beginAttempt(photo);
        photo.state = 'queued';
        photo.error = null;
        this.jobQueue.push(photo);
        this.processQueue();
        this.render();
    }

    async retryExistingBackendPhoto(photo) {
        try {
            const completed = await completePhoto(this.session.public_id, this.session.token, photo.backendPublicId, { signal: new AbortController().signal });
            photo.state = completed.verified ? 'uploaded' : 'needs_reselection';
            photo.optimizedBytes = completed.file_size || photo.optimizedBytes;
            photo.optimizedWidth = completed.width || photo.optimizedWidth;
            photo.optimizedHeight = completed.height || photo.optimizedHeight;
            this.render();
        } catch (error) {
            if (error && error.code === 'upload_not_ready') {
                await this.replaceUnusableBackendPhoto(photo);
                return;
            }

            photo.state = 'failed';
            this.render();
        }
    }

    // Locked retry invariant: DELETE the unusable backend row and wait for success
    // before ever allocating a replacement. Never allocate before that DELETE succeeds.
    async replaceUnusableBackendPhoto(photo) {
        if (!photo.file) {
            // No local file to re-upload with (e.g. a resumed photo): needs manual reselection.
            photo.state = 'needs_reselection';
            this.render();
            return;
        }

        photo.state = 'retry_cleanup';
        this.render();

        try {
            await deletePhoto(this.session.public_id, this.session.token, photo.backendPublicId, { signal: new AbortController().signal });
        } catch (error) {
            if (error && error.code === 'invalid_session') {
                this.clearSessionStorage();
            }

            // DELETE failed: keep the id, keep the card, do not allocate a replacement.
            photo.state = 'retry_cleanup_failed';
            this.render();
            return;
        }

        photo.backendPublicId = null;
        this.requeueForFreshUpload(photo);
    }


    async retryRemovePhoto(photo) {
        if (!photo || !this.session) {
            return;
        }

        if (!photo.backendPublicId) {
            // The original allocation outcome was ambiguous; reconcile against the session
            // before deciding whether a backend row actually needs deleting.
            photo.state = 'removing';
            this.render();

            try {
                const response = await readSession(this.session.public_id, this.session.token);
                const knownIds = new Set(
                    this.photos
                        .filter((item) => item.id !== photo.id && item.backendPublicId)
                        .map((item) => item.backendPublicId),
                );
                const orphan = (response.photos || []).find((item) => !knownIds.has(item.public_id));

                if (!orphan) {
                    this.revokePreview(photo);
                    this.photos = this.photos.filter((item) => item.id !== photo.id);
                    this.render();
                    return;
                }

                photo.backendPublicId = orphan.public_id;
            } catch {
                photo.state = 'remove_failed';
                this.render();
                return;
            }
        }

        photo.removalRequested = true;
        photo.cancelled = true;
        await this.finalizeRemoval(photo);
    }

    async recoverPendingPhoto(photo) {
        if (!photo || !photo.backendPublicId) {
            photo.state = 'needs_reselection';
            this.render();
            return;
        }

        try {
            const completed = await completePhoto(this.session.public_id, this.session.token, photo.backendPublicId, { signal: new AbortController().signal });
            photo.state = completed.verified ? 'uploaded' : 'needs_reselection';
            this.render();
        } catch (error) {
            if (error && error.code === 'upload_not_ready') {
                photo.state = 'needs_reselection';
                this.render();
                return;
            }

            photo.state = 'failed';
            this.render();
        }
    }

    async removePhoto(photo) {
        if (!photo) {
            return;
        }

        this.jobQueue = this.jobQueue.filter((item) => item.id !== photo.id);

        if (photo.state === 'allocating' && !photo.backendPublicId) {
            photo.cancelled = true;
            photo.generation = (photo.generation ?? 0) + 1;

            if (!photo.allocationDispatched) {
                // No request was ever sent: no backend row exists, so this is a plain local removal.
                if (photo.abortController && !photo.abortController.signal.aborted) {
                    photo.abortController.abort();
                }

                this.revokePreview(photo);
                this.photos = this.photos.filter((item) => item.id !== photo.id);
                this.render();
                return;
            }

            // The allocate request is already dispatched: the server may create a row we still
            // need to clean up, so do not abort it and do not drop the card until it settles.
            photo.removalRequested = true;
            photo.state = 'removing';
            this.render();
            return;
        }

        photo.cancelled = true;
        photo.removalRequested = true;

        if (photo.abortController && !photo.abortController.signal.aborted) {
            photo.abortController.abort();
        }

        if (photo.backendPublicId && this.session) {
            await this.finalizeRemoval(photo);
            return;
        }

        this.revokePreview(photo);
        this.photos = this.photos.filter((item) => item.id !== photo.id);
        this.render();
    }
}


