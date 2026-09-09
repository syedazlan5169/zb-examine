function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') || '' : '';
}

function normalizeErrorResponse(response, status) {
    if (response && typeof response === 'object' && 'code' in response) {
        return {
            code: response.code || 'request_failed',
            message: response.message,
            status: response.status || status,
        };
    }

    // Infrastructure-level failures (e.g. a proxy 413) return a non-JSON body; never surface it raw.
    return {
        code: 'request_failed',
        message: undefined,
        status,
    };
}

function extractSignal(optionsOrSignal) {
    if (!optionsOrSignal) {
        return undefined;
    }

    if (typeof optionsOrSignal === 'object' && 'signal' in optionsOrSignal) {
        return optionsOrSignal.signal;
    }

    return optionsOrSignal;
}

function uncertainPutError(status) {
    const error = normalizeErrorResponse(null, status);
    error.directPutUncertain = true;

    return error;
}

function putDirectObject(authorization, file, signal) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        let settled = false;

        const cleanup = () => {
            signal?.removeEventListener('abort', abort);
        };

        const settle = (callback, value) => {
            if (settled) {
                return;
            }

            settled = true;
            cleanup();
            callback(value);
        };

        const abort = () => {
            xhr.abort();
        };

        xhr.open(authorization.method, authorization.url, true);
        xhr.withCredentials = false;
        xhr.timeout = 30_000;

        Object.entries(authorization.required_headers || {}).forEach(([name, value]) => {
            xhr.setRequestHeader(name, value);
        });

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                settle(resolve, { status: 'stored' });
                return;
            }

            settle(reject, uncertainPutError(xhr.status));
        };
        xhr.onerror = () => settle(reject, uncertainPutError(xhr.status || 0));
        xhr.ontimeout = () => settle(reject, uncertainPutError(xhr.status || 0));
        xhr.onabort = () => {
            const error = new DOMException('The upload was aborted.', 'AbortError');
            settle(reject, error);
        };

        if (signal?.aborted) {
            xhr.abort();
            return;
        }

        signal?.addEventListener('abort', abort, { once: true });
        xhr.send(file);
    });
}

export async function requestJson(url, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = { Accept: 'application/json', ...(options.headers || {}) };

    if (options.body && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    const csrf = getCsrfToken();
    if (csrf && !['GET', 'HEAD'].includes(method) && !('X-CSRF-TOKEN' in headers)) {
        headers['X-CSRF-TOKEN'] = csrf;
    }

    const response = await fetch(url, {
        ...options,
        method,
        headers,
    });

    const text = await response.text();
    let json = null;

    if (text) {
        try {
            json = JSON.parse(text);
        } catch {
            json = null;
        }
    }

    if (!response.ok) {
        const error = normalizeErrorResponse(json, response.status);
        throw error;
    }

    return json;
}

export function sessionHeaders(token) {
    return {
        'X-Photo-Upload-Token': token,
    };
}

export async function createSession() {
    return requestJson('/photo-upload-sessions', {
        method: 'POST',
    });
}

export async function readSession(publicId, token) {
    return requestJson(`/photo-upload-sessions/${publicId}`, {
        method: 'GET',
        headers: sessionHeaders(token),
    });
}

export async function allocatePhoto(publicId, token, options = {}) {
    return requestJson(`/photo-upload-sessions/${publicId}/photos`, {
        method: 'POST',
        headers: sessionHeaders(token),
        signal: extractSignal(options),
    });
}

export async function uploadPhoto(publicId, token, photoPublicId, file, options = {}) {
    if (options.uploadMode === 'direct') {
        const authorization = await authorizePhoto(publicId, token, photoPublicId, options);

        return putDirectObject(authorization, file, extractSignal(options));
    }

    const formData = new FormData();
    formData.append('photo', file, file.name || 'photo.jpg');

    return requestJson(`/photo-upload-sessions/${publicId}/photos/${photoPublicId}/upload`, {
        method: 'POST',
        body: formData,
        headers: sessionHeaders(token),
        signal: extractSignal(options),
    });
}

export async function authorizePhoto(publicId, token, photoPublicId, options = {}) {
    return requestJson(`/photo-upload-sessions/${publicId}/photos/${photoPublicId}/authorize`, {
        method: 'POST',
        headers: sessionHeaders(token),
        signal: extractSignal(options),
    });
}

export async function completePhoto(publicId, token, photoPublicId, options = {}) {
    return requestJson(`/photo-upload-sessions/${publicId}/photos/${photoPublicId}/complete`, {
        method: 'POST',
        headers: sessionHeaders(token),
        signal: extractSignal(options),
    });
}

export async function deletePhoto(publicId, token, photoPublicId, options = {}) {
    return requestJson(`/photo-upload-sessions/${publicId}/photos/${photoPublicId}`, {
        method: 'DELETE',
        headers: sessionHeaders(token),
        signal: extractSignal(options),
    });
}
