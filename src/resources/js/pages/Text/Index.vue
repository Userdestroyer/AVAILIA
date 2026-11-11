<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowUp, Brain, Disc3, Globe, Hash, Info, MessageSquare, Paperclip, Plus, RotateCcw, SlidersHorizontal, Users, X, Zap,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, toRef, watch, watchEffect } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AnswerCard from '@/components/chat/AnswerCard.vue';
import AnswerCarousel from '@/components/chat/AnswerCarousel.vue';
import MessageActions from '@/components/chat/MessageActions.vue';
import GlassToggle from '@/components/GlassToggle.vue';
import ModelPicker from '@/components/ModelPicker.vue';
import ModeShell from '@/components/ModeShell.vue';
import EmptyHero from '@/components/chat/EmptyHero.vue';
import UsageRing from '@/components/chat/UsageRing.vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { fuzzy, useComposerMenu } from '@/composables/useComposerMenu';
import { useChatPreferences } from '@/composables/useChatPreferences';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { useThinking } from '@/composables/useThinking';
import { deleteJson, postJson } from '@/lib/http';

defineOptions({ layout: AppLayout });

const props = defineProps({
    vendors: { type: Array, required: true },
    // Картриджи контекста юзера — без содержимого: названия, теги, закреплён ли, размер
    plates: { type: Array, default: () => [] },
    chat: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
});

const thinking = useThinking();

// ── Режим запроса и модели ─────────────────────────────
const REQUEST_MODES = [
    { value: 'single', label: 'Обычный', title: 'Обычный запрос', icon: MessageSquare },
    { value: 'multi', label: 'Мульти-запрос', title: 'Один вопрос — сразу нескольким моделям', icon: Zap },
    { value: 'council', label: 'Консилиум', title: 'Консилиум моделей — скоро', icon: Users, disabled: true },
];
// Совпадает с StoreMessageRequest::MAX_MODELS
const MAX_MODELS = 4;

const allModels = computed(() => props.vendors.flatMap((v) => v.models.map((m) => ({ ...m, vendor: v.slug }))));
const exists = (id) => allModels.value.some((m) => m.id === id);

// Восстанавливаем последний выбор; модели, которых больше нет (сменили провайдера), отбрасываем
const user = usePage().props.auth.user;
const preferences = useChatPreferences(user?.id, user?.settings?.chat);
if (!exists(preferences.model_id)) {
    preferences.model_id = exists(props.chat?.ai_model_id) ? props.chat.ai_model_id : allModels.value[0]?.id;
}
preferences.multi_ids = preferences.multi_ids.filter(exists);
if (!preferences.multi_ids.length) preferences.multi_ids = [preferences.model_id];

const requestMode = toRef(preferences, 'mode');
const modelId = toRef(preferences, 'model_id');
const multiIds = toRef(preferences, 'multi_ids');

// Новая модель в мульти-запросе — по возможности от вендора, которого ещё нет: так интереснее сравнивать
const addModel = () => {
    const chosen = multiIds.value.map((id) => allModels.value.find((m) => m.id === id));
    const vendors = new Set(chosen.map((m) => m?.vendor));
    const free = allModels.value.filter((m) => !multiIds.value.includes(m.id));
    const next = free.find((m) => !vendors.has(m.vendor)) ?? free[0];
    if (next && multiIds.value.length < MAX_MODELS) multiIds.value.push(next.id);
};

const removeModel = (index) => multiIds.value.splice(index, 1);

watch(requestMode, (mode, previous) => {
    if (mode === 'multi') {
        if (multiIds.value[0] !== modelId.value) multiIds.value = [modelId.value];
        if (multiIds.value.length === 1) addModel();
    } else if (previous === 'multi') {
        modelId.value = multiIds.value[0];
    }
});

const targetModels = computed(() => (requestMode.value === 'multi' ? multiIds.value : [modelId.value]));

// ── Параметры ответа ───────────────────────────────────
const TOKEN_STEPS = [256, 512, 1024, 2048, 4096, 8192, 16384, 32768];
// 4K по умолчанию: у рассуждающих моделей мысли тоже тратят лимит ответа
const tokenStep = toRef(preferences, 'token_step');
const maxTokens = computed(() => TOKEN_STEPS[tokenStep.value]);
const maxTokensLabel = computed(() => (maxTokens.value >= 1024 ? `${maxTokens.value / 1024}K` : maxTokens.value));

const EFFORTS = [
    { value: 'low', label: 'Быстрый' },
    { value: 'medium', label: 'Стандартный' },
    { value: 'high', label: 'Глубокий' },
];
const effort = toRef(preferences, 'effort');
const deepThinking = toRef(preferences, 'deep_thinking');

// ── Возможности выбранных моделей ──────────────────────
// Настройка уходит каждой модели настолько, насколько та её умеет (сервер отбросит лишнее сам);
// здесь — только честно показать, кому она не достанется
const targetEntries = computed(() => targetModels.value.map((id) => allModels.value.find((m) => m.id === id)).filter(Boolean));
const withCapability = (capability) => targetEntries.value.filter((m) => m.capabilities?.[capability]);
// «GPT-6 Luna, Claude Opus 5.5 — не принимают температуру»
const withoutHint = (capability, single, plural) => {
    const missing = targetEntries.value.filter((m) => !m.capabilities?.[capability]);
    if (!missing.length) return null;
    return `${missing.map((m) => m.name).join(', ')} — ${missing.length === 1 ? single : plural}`;
};

// Температура: null — по умолчанию модели
const temperature = toRef(preferences, 'temperature');
const temperatureModels = computed(() => withCapability('temperature'));
const temperatureMax = computed(() => Math.max(1, ...temperatureModels.value.map((m) => m.capabilities.temperature.max ?? 2)));
const temperatureHint = computed(() => withoutHint('temperature', 'не принимает температуру, отвечает как задумано', 'не принимают температуру, отвечают как задумано'));
const reasoningHint = computed(() => (deepThinking.value ? withoutHint('reasoning', 'не регулирует глубину рассуждений', 'не регулируют глубину рассуждений') : null));

// ── Веб-поиск ──────────────────────────────────────────
const webSearch = toRef(preferences, 'web_search');
// Каждый поиск 0,5–1,5 ₽ + найденные страницы как ввод; больше 20 за ответ не пускает сервер
const SEARCH_MAX = 20;
const maxWebSearches = toRef(preferences, 'max_web_searches');
// Применяем по Enter/уходу из поля: пока печатают «15», промежуточная «1» не должна сохраняться
const setSearchLimit = (event) => {
    const value = Math.round(Number(event.target.value));
    maxWebSearches.value = Number.isFinite(value) && value >= 1 ? Math.min(SEARCH_MAX, value) : maxWebSearches.value;
    event.target.value = maxWebSearches.value;
};
const domainsInput = toRef(preferences, 'domains');
const allowedDomains = computed(() => String(domainsInput.value ?? '').split(/[\s,;]+/).map((d) => d.trim()).filter(Boolean));

// Что умеют выбранные модели в поиске — подсказки под его настройками
const searchHints = computed(() => {
    const names = (filter) => targetEntries.value.filter((m) => filter(m.capabilities?.web_search)).map((m) => m.name).join(', ');
    return [
        names((s) => !s) && `${names((s) => !s)} — без поиска, ответит по своим знаниям`,
        names((s) => s && !s.max_uses) && `${names((s) => s && !s.max_uses)} — число поисков решает сам`,
        allowedDomains.value.length && names((s) => s && !s.domains) && `${names((s) => s && !s.domains)} — не умеет ограничивать сайты`,
    ].filter(Boolean);
});

const showSettings = ref(false);
// Широкий экран — панель настроек стоит колонкой справа, иначе всплывает по кнопке
const isWide = useMediaQuery('(min-width: 1280px)');

// ── Переписка ──────────────────────────────────────────
const chatId = ref(props.chat?.id ?? null);
const messages = ref([...props.messages]);
// Экран сам двигается только при отправке (показать вопрос и начало ответа) и при открытии чата.
// Пока модель печатает, текст растёт вниз, а экран стоит — не рябит в глазах
const scroller = ref(null);

// Ход разговора: вопрос и ответы на него (в мульти-запросе — несколько)
const turns = computed(() => {
    const result = [];
    const byQuestion = new Map();

    for (const message of messages.value) {
        if (message.role === 'user') {
            const turn = { question: message, answers: [] };
            result.push(turn);
            byQuestion.set(message.id, turn);
        } else {
            const turn = byQuestion.get(message.parent_id) ?? result.at(-1);
            if (turn) turn.answers.push(message);
            else result.push({ question: null, answers: [message] });
        }
    }

    return result;
});

// ── Оболочка приложения (App Shell) ────────────────────
// Каркас (панели, поле ввода, настройки) остаётся на месте, меняется только лента: клик по чату
// сразу её очищает и показывает полосу загрузки, «Новый чат» — пустой экран без ожидания сервера
const switching = ref(false);
const isEmpty = computed(() => !messages.value.length && !switching.value);

// Только настоящий переход: предзагрузка (наведение, вкладки режимов) и частичные перезагрузки — не в счёт
const chatVisit = (visit) => visit.method === 'get' && !visit.prefetch && !visit.only?.length
    && /^\/text(\/|$)/.test(visit.url.pathname) && visit.url.pathname !== window.location.pathname;

const stopStart = router.on('start', (event) => {
    if (!chatVisit(event.detail.visit)) return;
    closeAllStreams();
    messages.value = [];
    chatId.value = null;
    // Новый чат пуст и так — показываем его сразу; чужой чат — полосу загрузки
    switching.value = event.detail.visit.url.pathname !== '/text';
});
const stopFinish = router.on('finish', () => (switching.value = false));
onBeforeUnmount(() => {
    stopStart();
    stopFinish();
});

// Длинный чат рендерится в два шага: сначала последние ходы (их и видно), остальное — когда браузер
// свободен. Markdown сотни сообщений разом блокировал бы ввод и давал мерцание при открытии
const FIRST_TURNS = 12;
const renderFrom = ref(0);
const visibleTurns = computed(() => turns.value.slice(renderFrom.value));
const idle = window.requestIdleCallback ?? ((fn) => setTimeout(fn, 50));
let renderToken = 0;

const showChat = () => {
    const token = ++renderToken;
    renderFrom.value = Math.max(0, turns.value.length - FIRST_TURNS);
    scrollToBottom(false);
    if (renderFrom.value) {
        idle(() => {
            if (token !== renderToken) return;
            renderFrom.value = 0;
            scrollToBottom(false);
        });
    }
};
onMounted(showChat);

// Переход между чатами (или на «Новый чат») — тот же компонент с новыми props
watch(() => props.messages, (value) => {
    closeAllStreams();
    chatId.value = props.chat?.id ?? null;
    messages.value = [...value];
    attachedPlates.value = initialPlates();
    showChat();
});

// Поле ввода переезжает между центром (пустой чат) и низом плавно: FLIP — запоминаем, где было,
// и анимируем только transform от старого места к новому (60 fps, без перерасчёта раскладки)
const composer = ref(null);
let composerTop = null;
watch(isEmpty, () => (composerTop = composer.value?.getBoundingClientRect().top ?? null), { flush: 'pre' });
watch(isEmpty, () => {
    const el = composer.value;
    if (!el || composerTop === null) return;
    const shift = composerTop - el.getBoundingClientRect().top;
    if (Math.abs(shift) > 1) {
        el.animate([{ transform: `translateY(${shift}px)` }, { transform: 'none' }], { duration: 520, easing: 'cubic-bezier(0.32, 0.72, 0, 1)' });
    }
}, { flush: 'post' });

const scrollToBottom = async (smooth = true) => {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
};

// ── Ввод ───────────────────────────────────────────────
const message = ref('');
const textarea = ref(null);
const sending = ref(false);
const activeStreams = ref(0);
const error = ref(null);

const busy = computed(() => sending.value || activeStreams.value > 0);

// ── Энергия (лимиты) ───────────────────────────────────
// Знаем заполнение окон заранее — исчерпанное блокирует отправку сразу, не дожидаясь отказа сервера
const page = usePage();
const usage = computed(() => page.props.usage);
const exhausted = computed(() => {
    for (const [kind, label] of [['session', 'сессии'], ['week', 'недели']]) {
        const window = usage.value?.windows?.text?.[kind];
        if (window?.limit && window.spent >= window.limit) {
            const at = new Date(window.resets_at);
            const sameDay = at.toDateString() === new Date().toDateString();
            const when = at.toLocaleString('ru', sameDay ? { hour: '2-digit', minute: '2-digit' } : { weekday: 'short', hour: '2-digit', minute: '2-digit' });
            return `Энергия ${label} закончилась — восстановится ${sameDay ? 'в ' : ''}${when}`;
        }
    }
    return null;
});

// Огонь горит всю генерацию: от отправки, пока пишет хоть одна модель
watchEffect(() => {
    thinking.value = busy.value;
});

// ── Контекст запроса: картриджи (#) и призванные модели (@) ──
// В начатом чате — те же картриджи, что у последнего вопроса; в новом — закреплённые
const plateExists = (id) => props.plates.some((p) => p.id === id);
const initialPlates = () =>
    (props.chat?.plate_ids?.length ? props.chat.plate_ids : props.plates.filter((p) => p.is_pinned).map((p) => p.id)).filter(plateExists);
const attachedPlates = ref(initialPlates());
const attached = computed(() => attachedPlates.value.map((id) => props.plates.find((p) => p.id === id)).filter(Boolean));
const attachedTokens = computed(() => attached.value.reduce((sum, p) => sum + p.tokens, 0));
const attachPlates = (ids) => (attachedPlates.value = [...new Set([...attachedPlates.value, ...ids])]);
const detachPlate = (id) => (attachedPlates.value = attachedPlates.value.filter((p) => p !== id));

// @модель в сообщении: этот вопрос — именно ей (несколько — мульти-запрос), выбор в панели не меняется
const mentioned = ref([]);
const mentionedModels = computed(() => mentioned.value.map((id) => allModels.value.find((m) => m.id === id)).filter(Boolean));
const sendTargets = computed(() => (mentioned.value.length ? mentioned.value : targetModels.value));

const menu = useComposerMenu(textarea, message, {
    '@': (query) => allModels.value
        .filter((m) => !mentioned.value.includes(m.id) && fuzzy(query, m.name, m.vendor, m.description))
        .map((m) => ({ key: `m${m.id}`, kind: 'model', model: m, label: m.name, hint: m.description })),
    '#': (query) => {
        // Тег — отдельной строкой, только если подключает сразу несколько ещё не подключённых картриджей;
        // тег одного картриджа — дубль, такой картридж находится по своим тегам и так
        const free = props.plates.filter((p) => !attachedPlates.value.includes(p.id));
        const tags = [...new Set(free.flatMap((p) => p.tags))]
            .filter((tag) => fuzzy(query, tag))
            .map((tag) => ({ tag, ids: free.filter((p) => p.tags.includes(tag)).map((p) => p.id) }))
            .filter(({ ids }) => ids.length > 1)
            .map(({ tag, ids }) => ({ key: `t${tag}`, kind: 'tag', ids, label: `#${tag}`, hint: `${ids.length} ${ids.length < 5 ? 'картриджа' : 'картриджей'}` }));
        const plates = free
            .filter((p) => fuzzy(query, p.title, ...p.tags))
            .map((p) => ({ key: `p${p.id}`, kind: 'plate', ids: [p.id], label: p.title, hint: p.tags.map((t) => `#${t}`).join(' ') }));
        return [...tags, ...plates];
    },
});

const { items: menuItems, index: menuIndex, open: menuOpen } = menu;

// Кнопка `#` рядом со скрепкой: вставляет триггер и открывает подсказки (на телефоне удобнее, чем искать символ)
const insertTrigger = (char) => {
    const el = textarea.value;
    const at = el.selectionStart ?? message.value.length;
    const glue = at > 0 && !/\s/.test(message.value[at - 1]) ? ' ' : '';
    message.value = message.value.slice(0, at) + glue + char + message.value.slice(at);
    nextTick(() => {
        el.focus();
        el.setSelectionRange(at + glue.length + 1, at + glue.length + 1);
        menu.detect();
    });
};

const pickFromMenu = (item) => {
    if (item.kind === 'model') {
        if (mentioned.value.length < MAX_MODELS) mentioned.value.push(item.model.id);
    } else {
        attachPlates(item.ids);
    }
};

const placeholder = computed(() => {
    if (mentionedModels.value.length > 1) return `Спросить ${mentionedModels.value.length} модели…`;
    if (mentionedModels.value.length) return `Спросить ${mentionedModels.value[0].name}…`;
    return requestMode.value === 'multi' ? `Спросить сразу ${multiIds.value.length} модели…` : 'Написать сообщение…';
});

watch(message, async () => {
    await nextTick();
    const el = textarea.value;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
});

// Временный id сообщения до ответа сервера. Не crypto.randomUUID(): его нет вне HTTPS и localhost,
// а по http://<ip-в-локалке> отправка падала бы, оставив фон «думающим»
let localSeq = 0;

const send = async () => {
    const content = message.value.trim();
    if (!content || busy.value) return;
    if (exhausted.value) {
        error.value = exhausted.value;
        return;
    }

    error.value = null;
    sending.value = true;
    // Отсчёт скорости глазами человека: от нажатия до первого токена на экране
    const sentAt = performance.now();

    messages.value.push({
        id: `local-${++localSeq}`,
        role: 'user',
        status: 'done',
        content,
        // Пластинки над вопросом — сразу, не дожидаясь сервера
        context: attached.value.map((p) => ({ plate_id: p.id, title: p.title })),
    });
    const local = messages.value.length - 1;
    message.value = '';
    scrollToBottom();

    let started;
    try {
        started = await postJson('/text/messages', {
            chat_id: chatId.value,
            ai_model_ids: sendTargets.value,
            content,
            plate_ids: attachedPlates.value,
            max_tokens: maxTokens.value,
            reasoning_effort: deepThinking.value ? effort.value : null,
            temperature: temperatureModels.value.length ? temperature.value : null,
            web_search: webSearch.value,
            max_web_searches: webSearch.value ? maxWebSearches.value : null,
            allowed_domains: webSearch.value && allowedDomains.value.length ? allowedDomains.value : null,
        });
    } catch (e) {
        messages.value.splice(local, 1);
        message.value = content;
        error.value = e.message;
        // Отказ мог быть из-за лимита — кольцо энергии должно показать актуальное
        router.reload({ only: ['usage'] });
        return;
    } finally {
        sending.value = false;
    }

    messages.value[local] = started.user_message;
    mentioned.value = [];
    if (!chatId.value) {
        chatId.value = started.chat.id;
        window.history.replaceState(window.history.state, '', `/text/${started.chat.id}`);
    }

    // Все модели стартуют одновременно — у каждой свой стрим из Go-гейтвея
    for (const answer of started.answers) {
        messages.value.push({ ...answer.message, status: 'streaming' });
        stream(answer.stream_token, messages.value.at(-1), sentAt);
    }
};

// ── Стримы ответов из Go-гейтвея ───────────────────────
const sources = new Set();

const closeStream = (source) => {
    source.close();
    sources.delete(source);
    activeStreams.value = sources.size;
};

const closeAllStreams = () => {
    sources.forEach(closeStream);
};

const stream = (token, answer, sentAt) => {
    let finished = false;
    let clientTtft = null;
    // Первое видимое — текст или пузырёк поиска (как считает и гейтвей)
    const seen = () => (clientTtft ??= Math.round(performance.now() - sentAt));
    const source = new EventSource(`/gw/stream?token=${encodeURIComponent(token)}`);
    sources.add(source);
    activeStreams.value = sources.size;

    // Поиски модели — пузырьки над ответом; обновляем по id (ищу → ✓ / ✗)
    source.addEventListener('search', (event) => {
        seen();
        const search = JSON.parse(event.data);
        answer.searches ??= [];
        const index = answer.searches.findIndex((s) => s.id === search.id);
        if (index === -1) answer.searches.push(search);
        else answer.searches[index] = { ...answer.searches[index], ...search, query: search.query || answer.searches[index].query };
    });

    source.addEventListener('delta', (event) => {
        seen();
        answer.content += JSON.parse(event.data).text;
    });

    source.addEventListener('done', (event) => {
        finished = true;
        Object.assign(answer, JSON.parse(event.data).message);
        closeStream(source);
        // Замер — фоном, после ответа; не дошёл — не страшно
        if (clientTtft !== null) postJson(`/text/messages/${answer.id}/metrics`, { client_ttft_ms: clientTtft }).catch(() => {});
        // Ответы дописаны — обновляем историю (название чата) и энергию
        if (!sources.size) router.reload({ only: ['recentChats', 'usage'] });
    });

    source.addEventListener('fail', (event) => {
        finished = true;
        Object.assign(answer, { status: 'failed', error: JSON.parse(event.data).message });
        closeStream(source);
    });

    // Обрыв соединения: гейтвей допишет ответ сам, он появится в чате после обновления
    source.onerror = () => {
        if (!finished) {
            Object.assign(answer, { status: 'failed', error: 'Соединение прервалось. Ответ сохранится в чате — обновите страницу через минуту.' });
        }
        closeStream(source);
    };
};

onBeforeUnmount(closeAllStreams);

// ── Удаление сообщений ─────────────────────────────────
// Убираем сразу, удаляем фоном; не вышло — возвращаем как было
const deleteMessage = async (message) => {
    const snapshot = [...messages.value];
    const gone = new Set([message.id]);
    // Вопрос — вместе с ответами на него
    if (message.role === 'user') messages.value.forEach((m) => m.parent_id === message.id && gone.add(m.id));
    messages.value = messages.value.filter((m) => !gone.has(m.id));

    try {
        await deleteJson(`/text/messages/${message.id}`);
    } catch (e) {
        messages.value = snapshot;
        error.value = e.message;
    }
};

// На сенсорной клавиатуре Enter — перенос строки (отправка — кнопкой), на физической — отправка.
// Ctrl/⌘+Enter отправляет везде
const isTouch = useMediaQuery('(pointer: coarse)');
const onKeydown = (event) => {
    if (menu.onKeydown(event, pickFromMenu)) return;
    // Backspace в пустом поле снимает последнюю призванную модель
    if (event.key === 'Backspace' && !message.value && mentioned.value.length) {
        mentioned.value.pop();
        return;
    }
    if (event.key !== 'Enter' || event.isComposing) return;
    if (event.ctrlKey || event.metaKey || (!isTouch.value && !event.shiftKey)) {
        event.preventDefault();
        send();
    }
};
</script>

<template>
    <Head title="Текст" />

    <ModeShell>
        <!-- Пустой чат: логотип над полем ввода, всё по центру экрана -->
        <div v-if="isEmpty" class="flex min-h-0 flex-1 flex-col items-center justify-end px-4 pb-6 text-center md:pb-10">
            <EmptyHero />
        </div>

        <!-- Переписка: одно стекло на всю ленту, сообщения скроллятся внутри -->
        <div v-else v-frost class="glass relative flex min-h-0 flex-1 flex-col overflow-hidden rounded-[22px] md:rounded-[28px]">
            <!-- Чат грузится: лента уже пустая, каркас на месте -->
            <div v-if="switching" class="loading-bar absolute inset-x-6 top-0 z-10 h-0.5 overflow-hidden rounded-full" aria-label="Загружаю чат" />
            <div class="scroll-edge top-0" aria-hidden="true" />
            <div class="scroll-edge bottom-0 [--edge-to:top]" aria-hidden="true" />
            <div ref="scroller" class="scrollbar-glass flex-1 space-y-4 overflow-y-auto px-2 py-3 md:space-y-6 md:px-6 md:py-8">
                <template v-for="(turn, index) in visibleTurns" :key="turn.question?.id ?? `t${renderFrom + index}`">
                    <div v-if="turn.question" class="group flex flex-col items-end gap-0.5">
                        <!-- Какие картриджи ушли с вопросом -->
                        <div v-if="turn.question.context?.length" class="mb-0.5 flex max-w-[85%] flex-wrap justify-end gap-1">
                            <span
                                v-for="item in turn.question.context"
                                :key="item.plate_id"
                                class="flex items-center gap-1 text-[11px] text-fg/45"
                                :title="`Картридж «${item.title}» был подключён к вопросу`"
                            >
                                <Disc3 class="vein size-3" :stroke-width="1.5" />
                                {{ item.title }}
                            </span>
                        </div>
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-(--bubble-bg) px-3 py-2 text-[0.875rem] leading-relaxed whitespace-pre-wrap md:px-4 md:py-2.5 md:text-[16px]">
                            {{ turn.question.content }}
                        </div>
                        <!-- На телефоне видны всегда, на десктопе — при наведении -->
                        <MessageActions
                            class="transition md:opacity-0 md:group-focus-within:opacity-100 md:group-hover:opacity-100"
                            :text="turn.question.content"
                            :deletable="Number.isInteger(turn.question.id) && !turn.answers.some((a) => ['pending', 'streaming'].includes(a.status))"
                            @delete="deleteMessage(turn.question)"
                        />
                    </div>

                    <!-- Мульти-запрос — лента со свайпом, обычный — просто ответ -->
                    <AnswerCarousel v-if="turn.answers.length > 1" class="-mx-2 md:-mx-6" :answers="turn.answers" @delete="deleteMessage" />
                    <AnswerCard v-else-if="turn.answers.length" :answer="turn.answers[0]" @delete="deleteMessage" />
                </template>
            </div>
        </div>

        <p v-if="error" class="px-4 text-sm text-hot-soft">{{ error }}</p>

        <!-- Поле ввода -->
        <div ref="composer" class="relative">
            <!-- Подсказки: @ — модели, # — теги и картриджи -->
            <div v-if="menuOpen" class="absolute inset-x-0 bottom-full z-20 mb-2 md:right-auto md:w-[26rem]">
                <ul class="scrollbar-glass max-h-72 overflow-y-auto rounded-2xl border border-fg/10 bg-(--popover-bg) p-1 shadow-2xl backdrop-blur-xl">
                    <li v-for="(item, i) in menuItems" :key="item.key">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition"
                            :class="i === menuIndex ? 'bg-fg/10' : 'hover:bg-fg/5'"
                            @mousedown.prevent="pickFromMenu(menu.take(item))"
                            @mouseenter="menuIndex = i"
                        >
                            <VendorIcon v-if="item.kind === 'model'" :slug="item.model.vendor" />
                            <Hash v-else-if="item.kind === 'tag'" class="size-4 shrink-0 text-cool-soft" :stroke-width="1.5" />
                            <Disc3 v-else class="size-4 shrink-0 text-cool-soft" :stroke-width="1.5" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm">{{ item.label }}</span>
                                <span v-if="item.hint" class="block truncate text-xs text-fg/45">{{ item.hint }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </div>

            <form v-frost class="glass flex flex-col rounded-[22px] px-2 py-1.5 md:rounded-[28px] md:px-4 md:py-3" @submit.prevent="send">
                <!-- Что уйдёт с вопросом: призванные модели и пластинки картриджей (крутятся, пока модель отвечает) -->
                <div v-if="mentionedModels.length || attached.length" class="flex flex-wrap items-center gap-1 px-1 pb-1">
                    <span
                        v-for="m in mentionedModels"
                        :key="`m${m.id}`"
                        class="flex items-center gap-1 rounded-full bg-cool/15 py-0.5 pr-0.5 pl-2 text-xs text-cool-soft"
                    >
                        @{{ m.name }}
                        <button type="button" class="rounded-full p-0.5 opacity-60 hover:opacity-100" title="Не спрашивать эту модель" @click="mentioned = mentioned.filter((id) => id !== m.id)">
                            <X class="size-3" :stroke-width="2" />
                        </button>
                    </span>
                    <span
                        v-for="p in attached"
                        :key="`p${p.id}`"
                        class="glass-inset flex items-center gap-1 rounded-full py-0.5 pr-0.5 pl-1 text-xs text-fg/75"
                        :title="`≈ ${p.tokens} токенов к каждому запросу`"
                    >
                        <Disc3 class="size-3.5 text-cool-soft" :class="{ 'animate-spin [animation-duration:2.4s]': busy }" :stroke-width="1.5" />
                        {{ p.title }}
                        <button type="button" class="rounded-full p-0.5 text-fg/40 hover:text-fg" title="Не подключать к этому чату" @click="detachPlate(p.id)">
                            <X class="size-3" :stroke-width="2" />
                        </button>
                    </span>
                    <span v-if="attached.length" class="ml-auto pr-1 text-[11px] text-fg/35 tabular-nums" title="Примерно столько токенов картриджи добавят к каждому запросу">
                        ≈ {{ attachedTokens }} ток.
                    </span>
                </div>

                <div class="flex items-end gap-1 md:gap-2">
                    <button type="button" class="vein p-1.5 md:p-2" title="Прикрепить файл (скоро)" disabled>
                        <Paperclip class="size-[18px] md:size-5" :stroke-width="1.5" />
                    </button>
                    <button v-if="plates.length" type="button" class="vein p-1.5 hover:text-fg md:p-2" title="Подключить контекст (#)" @click="insertTrigger('#')">
                        <Hash class="size-[18px] md:size-5" :stroke-width="1.5" />
                    </button>
                    <textarea
                        ref="textarea"
                        v-model="message"
                        rows="1"
                        :placeholder="placeholder"
                        :enterkeyhint="isTouch ? 'enter' : 'send'"
                        class="max-h-[200px] flex-1 resize-none bg-transparent py-1.5 text-[15px] font-light outline-none placeholder:text-fg/45 md:py-2 md:text-[17px]"
                        @keydown="onKeydown"
                        @input="menu.detect()"
                        @click="menu.detect()"
                        @keyup="['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key) && menu.detect()"
                        @blur="menu.close()"
                    />
                    <UsageRing :usage="usage" :rate="page.props.energyRate" />
                    <button
                        type="submit"
                        :disabled="!message.trim() || busy || !!exhausted"
                        class="flex size-9 shrink-0 items-center justify-center rounded-full pill-active transition disabled:opacity-40 md:size-11"
                        title="Отправить"
                    >
                        <ArrowUp class="size-5" :stroke-width="2" />
                    </button>
                </div>
            </form>
        </div>

        <!-- Вторая половина пустого экрана — поле ввода стоит ровно по центру; тут же подсказка -->
        <div v-if="isEmpty" class="flex min-h-0 flex-1 flex-col items-center px-4 pt-3 text-center">
            <p class="text-xs text-on-sky/70 text-shadow-(--text-shadow-sky) md:text-sm">
                <span class="text-on-sky/90">@модель</span> — спросить другую · <span class="text-on-sky/90">#тег</span> — подключить контекст о себе
            </p>
        </div>

        <!-- Настройки на узких экранах открываются кнопкой в верхней строке -->
        <template #actions>
            <button
                v-frost
                type="button"
                class="glass shrink-0 rounded-full p-2 md:p-2.5 xl:hidden"
                :class="{ 'ring-1 ring-fg/40': showSettings }"
                title="Настройки модели"
                @click="showSettings = !showSettings"
            >
                <SlidersHorizontal class="vein size-5" :stroke-width="1.5" />
            </button>
        </template>

        <template #panel>
            <!-- Поверх ленты (узкие экраны) — то, что под панелью, размывается; тап мимо — закрыть -->
            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-200" leave-to-class="opacity-0">
                <div v-if="!isWide && showSettings" class="fixed inset-0 z-40 bg-black/30 backdrop-blur-md" @click="showSettings = false" />
            </Transition>

            <!-- Панель настроек: на широких экранах — своя колонка справа с матовостью из шейдера;
                 на узких — всплывает под верхней строкой с настоящим размытием -->
            <section
                v-frost="isWide"
                class="glass shrink-0 self-start rounded-3xl p-4"
                :class="isWide
                    ? 'w-[24rem]'
                    : ['scrollbar-glass fixed inset-x-4 top-[4.5rem] z-50 max-h-[calc(100dvh-6rem)] overflow-y-auto md:left-auto md:w-[24rem]', showSettings ? 'block' : 'hidden']"
            >
                <!-- Режим запроса -->
                <div class="glass-inset grid grid-cols-3 gap-0.5 rounded-2xl p-1">
                    <button
                        v-for="mode in REQUEST_MODES"
                        :key="mode.value"
                        type="button"
                        :disabled="mode.disabled"
                        :title="mode.title"
                        class="flex items-center justify-center gap-1.5 rounded-xl px-2 py-2 text-[13px] whitespace-nowrap transition disabled:cursor-default disabled:opacity-35"
                        :class="requestMode === mode.value ? 'pill-active' : 'text-fg/60 enabled:hover:text-fg'"
                        @click="requestMode = mode.value"
                    >
                        <component :is="mode.icon" class="size-4" :stroke-width="1.5" />
                        {{ mode.label }}
                    </button>
                </div>

                <!-- Модели -->
                <div class="mt-3 space-y-2">
                    <ModelPicker v-if="requestMode === 'single'" v-model="modelId" :vendors="vendors" />

                    <template v-else>
                        <div v-for="(id, index) in multiIds" :key="index" class="flex items-center gap-2">
                            <ModelPicker
                                v-model="multiIds[index]"
                                class="min-w-0 flex-1"
                                :vendors="vendors"
                                :disabled-ids="multiIds.filter((_, other) => other !== index)"
                            />
                            <button
                                v-if="multiIds.length > 1"
                                type="button"
                                class="rounded-xl p-2 text-fg/50 hover:bg-fg/5 hover:text-fg"
                                title="Убрать модель"
                                @click="removeModel(index)"
                            >
                                <X class="size-4" :stroke-width="1.5" />
                            </button>
                        </div>
                        <button
                            v-if="multiIds.length < MAX_MODELS"
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-fg/15 py-2.5 text-sm text-fg/60 transition hover:border-fg/30 hover:text-fg"
                            @click="addModel"
                        >
                            <Plus class="size-4" :stroke-width="1.5" />
                            Добавить модель
                        </button>
                    </template>
                </div>

                <div class="vein-line my-4 h-px" />

                <!-- Размер ответа -->
                <label class="block">
                    <span class="flex items-center justify-between text-[15px]">
                        Лимит ответа, токенов
                        <span class="text-fg/90">{{ maxTokensLabel }}</span>
                    </span>
                    <input
                        v-model.number="tokenStep"
                        type="range"
                        min="0"
                        :max="TOKEN_STEPS.length - 1"
                        class="vein mt-3 h-1 w-full cursor-pointer accent-cool"
                    />
                </label>

                <!-- Effort -->
                <div class="mt-4 flex items-center justify-between">
                    <span class="flex items-center gap-2 text-[15px]">
                        Effort
                        <Info class="vein size-4" :stroke-width="1.5" title="Насколько глубоко модель рассуждает — работает вместе с Deep Thinking" />
                    </span>
                    <select
                        v-model="effort"
                        :disabled="!deepThinking"
                        class="cursor-pointer bg-transparent text-right text-[15px] outline-none disabled:cursor-default disabled:opacity-40"
                        :title="deepThinking ? '' : 'Включите Deep Thinking'"
                    >
                        <option v-for="e in EFFORTS" :key="e.value" :value="e.value" class="bg-(--popover-bg)">{{ e.label }}</option>
                    </select>
                </div>

                <!-- Температура -->
                <div class="mt-4">
                    <span class="flex items-center justify-between text-[15px]">
                        <span class="flex items-center gap-2">
                            Температура
                            <Info class="vein size-4" :stroke-width="1.5" title="Ниже — точнее и предсказуемее, выше — разнообразнее и смелее. «Авто» — как задумано моделью" />
                        </span>
                        <span class="flex items-center gap-1">
                            <button
                                v-if="temperature !== null"
                                type="button"
                                class="rounded-md p-1 text-fg/40 transition hover:text-fg"
                                title="Сбросить — как задумано моделью"
                                @click="temperature = null"
                            >
                                <RotateCcw class="size-3.5" :stroke-width="1.5" />
                            </button>
                            <span class="tabular-nums" :class="temperature === null || !temperatureModels.length ? 'text-fg/45' : 'text-fg/90'">
                                {{ temperature === null ? 'авто' : Number(temperature).toFixed(1) }}
                            </span>
                        </span>
                    </span>
                    <input
                        :value="temperature ?? 1"
                        type="range"
                        min="0"
                        :max="temperatureMax"
                        step="0.1"
                        :disabled="!temperatureModels.length"
                        class="vein mt-3 h-1 w-full cursor-pointer accent-cool transition-opacity disabled:cursor-default disabled:opacity-30"
                        :class="{ 'opacity-50': temperature === null }"
                        @input="temperature = Number($event.target.value)"
                    />
                    <p v-if="temperatureHint" class="mt-2 text-xs leading-snug text-fg/45">{{ temperatureHint }}</p>
                </div>

                <div class="vein-line my-4 h-px" />

                <!-- Переключатели -->
                <div class="space-y-3">
                    <GlassToggle v-model="webSearch" :icon="Globe" label="Web Search" />

                    <!-- Настройки поиска раскрываются под галочкой -->
                    <div
                        class="grid transition-[grid-template-rows] duration-300 ease-out"
                        :class="webSearch ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
                    >
                        <div class="min-h-0 overflow-hidden">
                            <div class="space-y-3 pt-1 pb-1 pl-8">
                                <div class="flex items-center justify-between gap-3">
                                    <label for="search-limit" class="text-sm text-fg/70">Поисков, не больше <span class="text-fg/40">(1–{{ SEARCH_MAX }})</span></label>
                                    <input
                                        id="search-limit"
                                        :value="maxWebSearches"
                                        type="number"
                                        min="1"
                                        :max="SEARCH_MAX"
                                        step="1"
                                        inputmode="numeric"
                                        class="glass-inset w-16 [appearance:textfield] rounded-xl px-2 py-1.5 text-center text-sm tabular-nums outline-none focus:border-cool/60 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                        @change="setSearchLimit"
                                        @keydown.enter="$event.target.blur()"
                                    />
                                </div>
                                <label class="block">
                                    <span class="text-sm text-fg/70">Только с сайтов</span>
                                    <input
                                        v-model="domainsInput"
                                        type="text"
                                        placeholder="cbr.ru, wikipedia.org"
                                        class="glass-inset mt-1.5 w-full rounded-xl px-3 py-2 text-sm outline-none placeholder:text-fg/30 focus:border-cool/60"
                                    />
                                </label>
                                <p v-for="hint in searchHints" :key="hint" class="text-xs leading-snug text-fg/45">{{ hint }}</p>
                            </div>
                        </div>
                    </div>

                    <GlassToggle v-model="deepThinking" :icon="Brain" label="Deep Thinking" />
                    <p v-if="reasoningHint" class="pl-8 text-xs leading-snug text-fg/45">{{ reasoningHint }}</p>
                </div>
            </section>
        </template>
    </ModeShell>
</template>
