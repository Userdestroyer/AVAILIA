import { computed, ref } from 'vue';

/**
 * Подсказки в поле ввода: `@` — призвать модель, `#` — подключить контекст.
 * Слово с триггером перед кареткой открывает меню; выбор убирает набранное и отдаёт пункт наружу
 * (чипом над полем ввода), стрелки и Enter/Tab — как в любом автодополнении.
 *
 * @param {import('vue').Ref<HTMLTextAreaElement|null>} textarea
 * @param {import('vue').Ref<string>} text
 * @param {Record<string, (query: string) => Array<{ key: string }>>} sources — пункты по триггеру
 */
export function useComposerMenu(textarea, text, sources) {
    const trigger = ref(null); // { char, query, start }
    const index = ref(0);

    const items = computed(() => (trigger.value ? sources[trigger.value.char]?.(trigger.value.query) ?? [] : []).slice(0, 7));
    const open = computed(() => items.value.length > 0);

    // Триггер — в начале строки или после пробела, до каретки, без пробелов внутри
    const detect = () => {
        const el = textarea.value;
        if (!el) return;
        // Из поля напрямую: v-model мог ещё не обновить text на этом же событии ввода
        const before = el.value.slice(0, el.selectionStart);
        const match = before.match(/(?:^|\s)([@#])([^\s@#]{0,40})$/u);
        const next = match ? { char: match[1], query: match[2], start: before.length - match[2].length - 1 } : null;
        if (next?.char !== trigger.value?.char || next?.query !== trigger.value?.query) index.value = 0;
        trigger.value = next;
    };

    const close = () => (trigger.value = null);

    /**
     * Убирает набранное «@запрос» и возвращает выбранный пункт.
     */
    const take = (item) => {
        const el = textarea.value;
        const { start } = trigger.value;
        const end = el.selectionStart;
        text.value = text.value.slice(0, start) + text.value.slice(end).replace(/^ /, '');
        close();
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(start, start);
        });
        return item;
    };

    /**
     * Клавиши, пока меню открыто: true — событие обработано, дальше его не пускать (иначе Enter отправит).
     */
    const onKeydown = (event, pick) => {
        if (!open.value) return false;
        const count = items.value.length;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            index.value = (index.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            pick(take(items.value[index.value]));
        } else if (event.key === 'Escape') {
            close();
        } else {
            return false;
        }
        event.preventDefault();
        return true;
    };

    return { trigger, items, index, open, detect, close, take, onKeydown };
}

/**
 * Совпадение для подсказок: буквы запроса по порядку, без регистра, пробелов и дефисов
 * («gem4» найдёт «Gemini 4 Argon»).
 */
export function fuzzy(query, ...haystack) {
    const normalize = (value) => String(value ?? '').toLowerCase().replace(/[\s\-_.]/g, '');
    const needle = normalize(query);
    return !needle || haystack.some((value) => {
        let at = 0;
        for (const char of normalize(value)) if (char === needle[at] && ++at === needle.length) return true;
        return false;
    });
}
