import { ref } from 'vue';
import { DEFAULT_THEME, THEMES } from '@/lib/theme';
import { patchJson } from '@/lib/http';

// Тему отдаёт сервер в <html data-theme> — стартуем с неё, без вспышки
const initial = document.documentElement.dataset.theme;
const theme = ref(THEMES[initial] ? initial : DEFAULT_THEME);

function apply(value) {
    theme.value = value;
    document.documentElement.dataset.theme = value;
    // Кука — чтобы и страница входа после выхода открывалась в выбранной теме
    document.cookie = `theme=${value}; path=/; max-age=31536000; samesite=lax`;
}

/**
 * Применяем сразу, сохраняем фоном; если сервер не принял — откатываем.
 */
async function setTheme(value) {
    const previous = theme.value;
    if (value === previous || !THEMES[value]) return;

    apply(value);

    try {
        await patchJson('/settings', { theme: value });
    } catch (error) {
        apply(previous);
        throw error;
    }
}

export function useTheme() {
    return { theme, setTheme };
}
