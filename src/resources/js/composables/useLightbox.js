import { reactive } from 'vue';

/**
 * Просмотр картинок поверх всего — одно окно на приложение (стоит в AppLayout).
 * items: [{ url, thumb_url, name }] — листаются стрелками.
 */
export const lightbox = reactive({ open: false, items: [], index: 0 });

export function openLightbox(items, index = 0) {
    lightbox.items = items;
    lightbox.index = index;
    lightbox.open = true;
}

export function closeLightbox() {
    lightbox.open = false;
}
