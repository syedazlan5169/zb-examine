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
    const formData = new FormData();
    formData.append('photo', file, file.name || 'photo.jpg');

    return requestJson(`/photo-upload-sessions/${publicId}/photos/${photoPublicId}/upload`, {
        method: 'POST',
        body: formData,
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
