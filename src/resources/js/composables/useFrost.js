/**
 * Матовые панели: фон-шейдер сам рисует под ними размытую версию всполохов.
 * Это заменяет backdrop-filter: blur, который браузер пересчитывал бы на каждом кадре
 * анимации под каждой панелью — дорого для GPU, особенно на встроенной графике.
 */
export const MAX_FROST_REGIONS = 8;

// Элемент → радиус скругления в CSS px
export const frostRegions = new Map();

export const vFrost = {
    mounted(el) {
        frostRegions.set(el, parseFloat(getComputedStyle(el).borderTopLeftRadius) || 0);
        el.dataset.frost = '';
    },
    unmounted(el) {
        frostRegions.delete(el);
    },
};
