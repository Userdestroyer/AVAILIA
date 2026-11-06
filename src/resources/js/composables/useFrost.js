/**
 * Матовые панели: фон-шейдер сам рисует под ними размытую версию всполохов.
 * Это заменяет backdrop-filter: blur, который браузер пересчитывал бы на каждом кадре
 * анимации под каждой панелью — дорого для GPU, особенно на встроенной графике.
 *
 * Шейдер размывает только фон: панели, которые всплывают поверх контента (шторка,
 * настройки на мобильном), должны размывать то, что под ними, — им v-frost="false",
 * и работает обычный CSS backdrop-filter.
 */
export const MAX_FROST_REGIONS = 8;

// Элемент → радиус скругления в CSS px
export const frostRegions = new Map();

function apply(el, enabled) {
    if (enabled) {
        frostRegions.set(el, parseFloat(getComputedStyle(el).borderTopLeftRadius) || 0);
        el.dataset.frost = '';
    } else {
        frostRegions.delete(el);
        delete el.dataset.frost;
    }
}

export const vFrost = {
    mounted(el, binding) {
        apply(el, binding.value !== false);
    },
    updated(el, binding) {
        if (binding.value !== binding.oldValue) apply(el, binding.value !== false);
    },
    unmounted(el) {
        frostRegions.delete(el);
    },
};
