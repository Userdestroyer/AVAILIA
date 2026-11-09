import { ref } from 'vue';
import { DEFAULTS, FONTS, STYLES, THEMES } from '@/lib/theme';
import { patchJson } from '@/lib/http';

/**
 * Оформление: стиль, схема и шрифт акцентов. Сервер отдаёт их в атрибутах <html> — стартуем с них,
 * без вспышки. Применяется сразу, сохраняется фоном; не принял сервер — откат.
 */
const OPTIONS = { style: STYLES, theme: THEMES, font: FONTS };
const root = document.documentElement;
const read = (key) => (OPTIONS[key][root.dataset[key]] ? root.dataset[key] : DEFAULTS[key]);

const state = {
    style: ref(read('style')),
    theme: ref(read('theme')),
    font: ref(read('font')),
};

function apply(key, value) {
    state[key].value = value;
    root.dataset[key] = value;
    // Кука — чтобы и страница входа после выхода открывалась в выбранном оформлении
    document.cookie = `${key}=${value}; path=/; max-age=31536000; samesite=lax`;
}

async function set(key, value) {
    const previous = state[key].value;
    if (value === previous || !OPTIONS[key][value]) return;

    apply(key, value);

    try {
        await patchJson('/settings', { [key]: value });
    } catch (error) {
        apply(key, previous);
        throw error;
    }
}

export function useTheme() {
    return {
        ...state,
        setTheme: (value) => set('theme', value),
        setStyle: (value) => set('style', value),
        setFont: (value) => set('font', value),
    };
}
