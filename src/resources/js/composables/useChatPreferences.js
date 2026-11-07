import { reactive, watch } from 'vue';
import { patchJson } from '@/lib/http';

/**
 * Последний выбор в панели чата: режим, модели, лимит ответа, температура, поиск, рассуждения.
 * Живёт на уровне приложения, а не страницы: страница «Текст» пересоздаётся при смене режима
 * (а её заранее загруженная копия несёт устаревшие настройки). Сохраняется в аккаунт фоном —
 * при перезагрузке и на других устройствах всё как было.
 */
const DEFAULTS = {
    mode: 'single',
    model_id: null,
    multi_ids: [],
    token_step: 4,
    effort: 'medium',
    deep_thinking: false,
    // null — температура по умолчанию модели
    temperature: null,
    web_search: false,
    max_web_searches: 3,
    domains: '',
};

let preferences = null;
let owner = null;
let timer = 0;

export function useChatPreferences(userId, saved) {
    // Другой пользователь в той же вкладке — начинаем с его настроек
    if (preferences && owner === userId) return preferences;

    owner = userId;
    // Пустые значения с сервера (Laravel превращает '' в null) — дефолты, иначе страница упадёт на них
    const known = Object.fromEntries(Object.entries(saved ?? {}).filter(([key, value]) => key in DEFAULTS && value !== null));
    preferences = reactive({ ...DEFAULTS, ...known });

    watch(preferences, () => {
        clearTimeout(timer);
        timer = setTimeout(() => patchJson('/settings', { chat: { ...preferences } }).catch(() => {}), 500);
    }, { deep: true });

    return preferences;
}
