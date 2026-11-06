<script setup>
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUp, Brain, Globe, Info, MessageSquare, Paperclip, Plus, SlidersHorizontal, Sparkle, Users, X, Zap,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AnswerCard from '@/components/chat/AnswerCard.vue';
import AnswerCarousel from '@/components/chat/AnswerCarousel.vue';
import GlassToggle from '@/components/GlassToggle.vue';
import ModelPicker from '@/components/ModelPicker.vue';
import ModeShell from '@/components/ModeShell.vue';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { useThinking } from '@/composables/useThinking';
import { postJson } from '@/lib/http';

defineOptions({ layout: AppLayout });

const props = defineProps({
    vendors: { type: Array, required: true },
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
const initialModel = exists(props.chat?.ai_model_id) ? props.chat.ai_model_id : allModels.value[0]?.id;

const requestMode = ref('single');
const modelId = ref(initialModel);
const multiIds = ref([initialModel]);

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
const tokenStep = ref(4);
const maxTokens = computed(() => TOKEN_STEPS[tokenStep.value]);
const maxTokensLabel = computed(() => (maxTokens.value >= 1024 ? `${maxTokens.value / 1024}K` : maxTokens.value));

const EFFORTS = [
    { value: 'low', label: 'Быстрый' },
    { value: 'medium', label: 'Стандартный' },
    { value: 'high', label: 'Глубокий' },
];
const effort = ref('medium');
const deepThinking = ref(false);

// ── Веб-поиск ──────────────────────────────────────────
const webSearch = ref(false);
// Каждый поиск ~1 ₽ + найденные страницы как ввод — держим лимит небольшим
const SEARCH_LIMITS = [1, 2, 3, 4, 5];
const maxWebSearches = ref(3);
const domainsInput = ref('');
const allowedDomains = computed(() => domainsInput.value.split(/[\s,;]+/).map((d) => d.trim()).filter(Boolean));

// Что умеют вендоры выбранных моделей — подсказки под настройками поиска
const targetVendors = computed(() => {
    const slugs = new Set(targetModels.value.map((id) => allModels.value.find((m) => m.id === id)?.vendor));
    return props.vendors.filter((v) => slugs.has(v.slug));
});
const searchHints = computed(() => {
    const names = (filter) => targetVendors.value.filter(filter).map((v) => v.product).join(', ');
    return [
        names((v) => !v.web_search) && `${names((v) => !v.web_search)} — без поиска, ответит по своим знаниям`,
        names((v) => v.web_search && !v.web_search.max_uses) && `${names((v) => v.web_search && !v.web_search.max_uses)} — число поисков решает сам`,
        allowedDomains.value.length && names((v) => v.web_search && !v.web_search.domains)
            && `${names((v) => v.web_search && !v.web_search.domains)} — не умеет ограничивать сайты`,
    ].filter(Boolean);
});

const showSettings = ref(false);
// Широкий экран — панель настроек стоит колонкой справа, иначе всплывает по кнопке
const isWide = useMediaQuery('(min-width: 1280px)');

// ── Переписка ──────────────────────────────────────────
const chatId = ref(props.chat?.id ?? null);
const messages = ref([...props.messages]);
const scroller = ref(null);
// Лента следует за ответом, только пока юзер внизу; пролистал выше почитать — не дёргаем
const stickToBottom = ref(true);

const onFeedScroll = () => {
    const el = scroller.value;
    stickToBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
};

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

// Переход между чатами (или на «Новый чат») — тот же компонент с новыми props
watch(() => props.messages, (value) => {
    closeAllStreams();
    chatId.value = props.chat?.id ?? null;
    messages.value = [...value];
    scrollToBottom(false, true);
});

const scrollToBottom = async (smooth = true, force = false) => {
    if (force) stickToBottom.value = true;
    if (!stickToBottom.value) return;
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

// Огонь горит всю генерацию: от отправки, пока пишет хоть одна модель
watchEffect(() => {
    thinking.value = busy.value;
});

const placeholder = computed(() =>
    requestMode.value === 'multi' ? `Спросить сразу ${multiIds.value.length} модели…` : 'Написать сообщение…');

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

    error.value = null;
    sending.value = true;

    messages.value.push({ id: `local-${++localSeq}`, role: 'user', status: 'done', content });
    const local = messages.value.length - 1;
    message.value = '';
    scrollToBottom(true, true);

    let started;
    try {
        started = await postJson('/text/messages', {
            chat_id: chatId.value,
            ai_model_ids: targetModels.value,
            content,
            max_tokens: maxTokens.value,
            reasoning_effort: deepThinking.value ? effort.value : null,
            web_search: webSearch.value,
            max_web_searches: webSearch.value ? maxWebSearches.value : null,
            allowed_domains: webSearch.value && allowedDomains.value.length ? allowedDomains.value : null,
        });
    } catch (e) {
        messages.value.splice(local, 1);
        message.value = content;
        error.value = e.message;
        return;
    } finally {
        sending.value = false;
    }

    messages.value[local] = started.user_message;
    if (!chatId.value) {
        chatId.value = started.chat.id;
        window.history.replaceState(window.history.state, '', `/text/${started.chat.id}`);
    }

    // Все модели стартуют одновременно — у каждой свой стрим из Go-гейтвея
    for (const answer of started.answers) {
        messages.value.push({ ...answer.message, status: 'streaming' });
        stream(answer.stream_token, messages.value.at(-1));
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

const stream = (token, answer) => {
    let finished = false;
    const source = new EventSource(`/gw/stream?token=${encodeURIComponent(token)}`);
    sources.add(source);
    activeStreams.value = sources.size;

    // Поиски модели — пузырьки над ответом; обновляем по id (ищу → ✓ / ✗)
    source.addEventListener('search', (event) => {
        const search = JSON.parse(event.data);
        answer.searches ??= [];
        const index = answer.searches.findIndex((s) => s.id === search.id);
        if (index === -1) answer.searches.push(search);
        else answer.searches[index] = { ...answer.searches[index], ...search, query: search.query || answer.searches[index].query };
    });

    source.addEventListener('delta', (event) => {
        answer.content += JSON.parse(event.data).text;
        scrollToBottom(false);
    });

    source.addEventListener('done', (event) => {
        finished = true;
        Object.assign(answer, JSON.parse(event.data).message);
        closeStream(source);
        if (!sources.size) router.reload({ only: ['recentChats'] });
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

const onKeydown = (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
};
</script>

<template>
    <Head title="Текст" />

    <ModeShell>
        <!-- Пустое состояние -->
        <div v-if="!messages.length" class="flex flex-1 flex-col items-center justify-center px-4 text-center">
            <Sparkle
                class="size-12 fill-current transition-colors duration-[2000ms]"
                :class="thinking ? 'text-hot drop-shadow-[0_0_24px_rgba(255,138,76,0.7)]' : 'text-cool drop-shadow-[0_0_24px_rgba(91,140,255,0.7)]'"
                :stroke-width="0"
            />
            <h1 class="mt-6 text-4xl font-extralight tracking-tight text-white/90">
                {{ thinking ? 'Думаю…' : 'Чем могу помочь?' }}
            </h1>
            <p class="mt-4 text-lg font-light text-white/60">
                Задайте вопрос, выберите модель или начните с примера…
            </p>
        </div>

        <!-- Переписка: одно стекло на всю ленту, сообщения скроллятся внутри -->
        <div v-else v-frost class="glass flex min-h-0 flex-1 flex-col overflow-hidden rounded-[28px]">
            <div ref="scroller" class="scroll-fade scrollbar-glass flex-1 space-y-6 overflow-y-auto px-6 py-8" @scroll.passive="onFeedScroll">
                <template v-for="(turn, index) in turns" :key="turn.question?.id ?? index">
                    <div v-if="turn.question" class="flex justify-end">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-(--bubble-bg) px-4 py-2.5 text-[16px] leading-relaxed whitespace-pre-wrap">
                            {{ turn.question.content }}
                        </div>
                    </div>

                    <!-- Мульти-запрос — лента со свайпом, обычный — просто ответ -->
                    <AnswerCarousel v-if="turn.answers.length > 1" class="-mx-6" :answers="turn.answers" />
                    <AnswerCard v-else-if="turn.answers.length" :answer="turn.answers[0]" />
                </template>
            </div>
        </div>

        <p v-if="error" class="px-4 text-sm text-hot-soft">{{ error }}</p>

        <!-- Поле ввода -->
        <form v-frost class="glass flex items-end gap-3 rounded-[28px] px-4 py-3" @submit.prevent="send">
            <button type="button" class="p-2 text-white/60 hover:text-white" title="Прикрепить файл (скоро)" disabled>
                <Paperclip class="size-5" :stroke-width="1.5" />
            </button>
            <textarea
                ref="textarea"
                v-model="message"
                rows="1"
                :placeholder="placeholder"
                class="max-h-[200px] flex-1 resize-none bg-transparent py-2 text-[17px] font-light outline-none placeholder:text-white/45"
                @keydown="onKeydown"
            />
            <button
                type="submit"
                :disabled="!message.trim() || busy"
                class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-b from-[#6b9bff] to-cool text-white shadow-lg shadow-cool/40 transition disabled:opacity-40"
                title="Отправить"
            >
                <ArrowUp class="size-5" :stroke-width="2" />
            </button>
        </form>

        <!-- Настройки на узких экранах открываются кнопкой в верхней строке -->
        <template #actions>
            <button
                v-frost
                type="button"
                class="glass shrink-0 rounded-full p-2.5 xl:hidden"
                :class="{ 'ring-1 ring-white/40': showSettings }"
                title="Настройки модели"
                @click="showSettings = !showSettings"
            >
                <SlidersHorizontal class="size-5" :stroke-width="1.5" />
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
                        :class="requestMode === mode.value ? 'bg-white/14 text-white shadow-sm' : 'text-white/60 enabled:hover:text-white'"
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
                                class="rounded-xl p-2 text-white/50 hover:bg-white/5 hover:text-white"
                                title="Убрать модель"
                                @click="removeModel(index)"
                            >
                                <X class="size-4" :stroke-width="1.5" />
                            </button>
                        </div>
                        <button
                            v-if="multiIds.length < MAX_MODELS"
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-white/15 py-2.5 text-sm text-white/60 transition hover:border-white/30 hover:text-white"
                            @click="addModel"
                        >
                            <Plus class="size-4" :stroke-width="1.5" />
                            Добавить модель
                        </button>
                    </template>
                </div>

                <div class="my-4 h-px bg-white/10" />

                <!-- Размер ответа -->
                <label class="block">
                    <span class="flex items-center justify-between text-[15px]">
                        Размер ответа (токены)
                        <span class="text-white/90">{{ maxTokensLabel }}</span>
                    </span>
                    <input
                        v-model.number="tokenStep"
                        type="range"
                        min="0"
                        :max="TOKEN_STEPS.length - 1"
                        class="mt-3 h-1 w-full cursor-pointer accent-cool"
                    />
                </label>

                <!-- Effort -->
                <div class="mt-4 flex items-center justify-between">
                    <span class="flex items-center gap-2 text-[15px]">
                        Effort
                        <Info class="size-4 text-white/40" :stroke-width="1.5" title="Насколько глубоко модель рассуждает — работает вместе с Deep Thinking" />
                    </span>
                    <select
                        v-model="effort"
                        :disabled="!deepThinking"
                        class="cursor-pointer bg-transparent text-right text-[15px] outline-none disabled:cursor-default disabled:opacity-40"
                        :title="deepThinking ? '' : 'Включите Deep Thinking'"
                    >
                        <option v-for="e in EFFORTS" :key="e.value" :value="e.value" class="bg-[#2c3445]">{{ e.label }}</option>
                    </select>
                </div>

                <div class="my-4 h-px bg-white/10" />

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
                                    <span class="text-sm text-white/70">Поисков, не больше</span>
                                    <div class="glass-inset flex rounded-xl p-0.5">
                                        <button
                                            v-for="limit in SEARCH_LIMITS"
                                            :key="limit"
                                            type="button"
                                            class="size-7 rounded-lg text-sm transition"
                                            :class="maxWebSearches === limit ? 'bg-white/15 text-white' : 'text-white/55 hover:text-white'"
                                            @click="maxWebSearches = limit"
                                        >
                                            {{ limit }}
                                        </button>
                                    </div>
                                </div>
                                <label class="block">
                                    <span class="text-sm text-white/70">Только с сайтов</span>
                                    <input
                                        v-model="domainsInput"
                                        type="text"
                                        placeholder="cbr.ru, wikipedia.org"
                                        class="glass-inset mt-1.5 w-full rounded-xl px-3 py-2 text-sm outline-none placeholder:text-white/30 focus:border-cool/60"
                                    />
                                </label>
                                <p v-for="hint in searchHints" :key="hint" class="text-xs leading-snug text-white/45">{{ hint }}</p>
                            </div>
                        </div>
                    </div>

                    <GlassToggle v-model="deepThinking" :icon="Brain" label="Deep Thinking" />
                </div>
            </section>
        </template>
    </ModeShell>
</template>
