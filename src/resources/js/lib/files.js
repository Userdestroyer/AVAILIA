/**
 * Подготовка файла к загрузке. Картинка жмётся, только если не влезает в лимит, и бережно:
 * сначала без уменьшения (WebP 0.92), потом мягче качество, потом уменьшение шагами —
 * но не меньше READABLE px по длинной стороне: текст на скриншоте должен читаться.
 * Модуль грузится отдельным чанком — только когда юзер прикрепляет файл.
 */
export const ACCEPT = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'];

// GIF не жмём (потеряется анимация), PDF — тем более
const COMPRESSIBLE = ['image/png', 'image/jpeg', 'image/webp'];
// Больше модели не разглядят: всё равно ужимают сами
const MAX_SIDE = 4096;
const READABLE = 1600;

export async function prepare(file, maxBytes) {
    if (!ACCEPT.includes(file.type)) throw new Error('Можно прикрепить картинку (PNG, JPEG, WebP, GIF) или PDF.');
    if (file.size <= maxBytes) return file;

    const mb = Math.round(maxBytes / 1024 / 1024);
    if (!COMPRESSIBLE.includes(file.type)) throw new Error(`«${file.name}» больше ${mb} МБ.`);

    const bitmap = await createImageBitmap(file);
    try {
        const long = Math.max(bitmap.width, bitmap.height);
        let scale = Math.min(1, MAX_SIDE / long);
        let quality = 0.92;
        for (;;) {
            const blob = await encode(bitmap, scale, quality);
            if (blob.size <= maxBytes) return new File([blob], rename(file.name, blob.type), { type: blob.type });
            if (quality > 0.85) quality = 0.85;
            else if (long * scale * 0.8 >= READABLE) scale *= 0.8;
            else throw new Error(`«${file.name}» не ужать до ${mb} МБ без потери читаемости.`);
        }
    } finally {
        bitmap.close();
    }
}

async function encode(bitmap, scale, quality) {
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);
    // OffscreenCanvas кодирует асинхронно и не трогает DOM
    const canvas = typeof OffscreenCanvas === 'undefined' ? Object.assign(document.createElement('canvas'), { width, height }) : new OffscreenCanvas(width, height);
    const context = canvas.getContext('2d');
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, width, height);

    const toBlob = (type) => (canvas.convertToBlob ? canvas.convertToBlob({ type, quality }) : new Promise((resolve) => canvas.toBlob(resolve, type, quality)));
    const webp = await toBlob('image/webp');
    if (webp?.type === 'image/webp') return webp;

    // Safari не кодирует WebP — тогда JPEG, прозрачное — на белом
    context.globalCompositeOperation = 'destination-over';
    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);
    return toBlob('image/jpeg');
}

function rename(name, type) {
    const base = name.replace(/\.[^.]+$/, '') || 'image';
    return `${base}.${type === 'image/webp' ? 'webp' : 'jpg'}`;
}
