const DEFAULT_STAGES = [
    { longestEdge: 2400, quality: 0.82, threshold: 1024 * 1024 },
    { longestEdge: 2400, quality: 0.72, threshold: 1024 * 1024 },
    { longestEdge: 2000, quality: 0.72, threshold: 2 * 1024 * 1024 },
];

function roundDimension(value) {
    const rounded = Math.round(value);
    return Math.max(1, rounded);
}

async function readImageSource(file, signal) {
    if ('createImageBitmap' in window) {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        return { bitmap, needsBitmapCleanup: true };
    }

    const url = URL.createObjectURL(file);
    const image = await new Promise((resolve, reject) => {
        const img = new Image();
        const cleanup = () => URL.revokeObjectURL(url);

        const abortListener = () => {
            cleanup();
            reject(new DOMException('Image decode aborted', 'AbortError'));
        };

        if (signal) {
            if (signal.aborted) {
                cleanup();
                reject(new DOMException('Image decode aborted', 'AbortError'));
                return;
            }

            signal.addEventListener('abort', abortListener, { once: true });
        }

        img.onload = () => {
            if (signal) {
                signal.removeEventListener('abort', abortListener);
            }
            cleanup();
            resolve(img);
        };

        img.onerror = () => {
            if (signal) {
                signal.removeEventListener('abort', abortListener);
            }
            cleanup();
            reject(new Error('unsupported-image'));
        };

        img.src = url;
    });

    return { image, needsBitmapCleanup: false };
}

async function createCanvasBlob(image, width, height, quality, signal) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');
    if (!context) {
        throw new Error('canvas-unavailable');
    }

    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.clearRect(0, 0, width, height);

    if (image instanceof ImageBitmap) {
        context.drawImage(image, 0, 0, width, height);
    } else {
        context.drawImage(image, 0, 0, width, height);
    }

    return new Promise((resolve, reject) => {
        if (signal && signal.aborted) {
            reject(new DOMException('Image decode aborted', 'AbortError'));
            return;
        }

        canvas.toBlob((blob) => {
            if (signal && signal.aborted) {
                reject(new DOMException('Image decode aborted', 'AbortError'));
                return;
            }

            if (!blob) {
                reject(new Error('compression-failed'));
                return;
            }

            resolve(blob);
        }, 'image/jpeg', quality);
    });
}

export async function optimizeImage(file, options = {}) {
    const signal = options.signal;
    const originalBytes = file.size || 0;

    const { bitmap, image, needsBitmapCleanup } = await readImageSource(file, signal);

    const source = bitmap || image;
    const originalWidth = source.width || 0;
    const originalHeight = source.height || 0;

    if (!originalWidth || !originalHeight) {
        if (bitmap && needsBitmapCleanup) {
            bitmap.close();
        }
        throw new Error('unsupported-image');
    }

    let candidateWidth = originalWidth;
    let candidateHeight = originalHeight;
    let stage = 0;
    let selectedBlob = null;
    let selectedQuality = null;

    for (const stageConfig of DEFAULT_STAGES) {
        const scale = Math.min(1, stageConfig.longestEdge / Math.max(originalWidth, originalHeight));
        candidateWidth = roundDimension(originalWidth * scale);
        candidateHeight = roundDimension(originalHeight * scale);

        if (candidateWidth < 1 || candidateHeight < 1) {
            candidateWidth = originalWidth;
            candidateHeight = originalHeight;
        }

        const blob = await createCanvasBlob(source, candidateWidth, candidateHeight, stageConfig.quality, signal);
        stage = stage + 1;
        selectedBlob = blob;
        selectedQuality = stageConfig.quality;

        if (blob.size <= stageConfig.threshold || stage === 3) {
            break;
        }
    }

    if (!selectedBlob) {
        if (bitmap && needsBitmapCleanup) {
            bitmap.close();
        }
        throw new Error('compression-failed');
    }

    if (selectedBlob.size > 2 * 1024 * 1024) {
        if (bitmap && needsBitmapCleanup) {
            bitmap.close();
        }
        throw new Error('photo-too-large');
    }

    if (bitmap && needsBitmapCleanup) {
        bitmap.close();
    }

    return {
        blob: selectedBlob,
        originalWidth,
        originalHeight,
        width: candidateWidth,
        height: candidateHeight,
        quality: selectedQuality,
        originalBytes,
        outputBytes: selectedBlob.size,
        stage,
    };
}
