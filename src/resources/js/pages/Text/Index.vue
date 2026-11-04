<script setup>
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUp, Brain, Check, ChevronDown, Globe, Info, Paperclip, SlidersHorizontal, Sparkle, Users, Zap,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import GlassToggle from '@/components/GlassToggle.vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { useThinking } from '@/composables/useThinking';
import { postJson } from '@/lib/http';

defineOptions({ layout: AppLayout });

const props = defineProps({
    vendors: { type: Array, required: true },
    chat: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
});

const thinking = useThinking();

// ── Модель ─────────────────────────────────────────────
const vendorOf = (modelId) => props.vendors.find((v) => v.models.some((m) => m.id === modelId));

const vendorSlug = ref((vendorOf(props.chat?.ai_model_id) ?? props.vendors[0])?.slug);
const vendor = computed(() => props.vendors.find((v) => v.slug === vendorSlug.value));
const modelId = ref(vendorOf(props.chat?.ai_model_id) ? props.chat.ai_model_id : vendor.value?.models[0]?.id);
const model = computed(() => vendor.value?.models.find((m) => m.id === modelId.value));
const modelMenu = ref(false);

watch(vendorSlug, () => {
    if (!vendor.value?.models.some((m) => m.id === modelId.value)) {
        modelId.value = vendor.value?.models[0]?.id;
    }
});

const formatPrice = (value, currency) =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);

// Копейки важны: короткий ответ может стоить доли рубля
const formatCost = ({ amount, currency }) => {
    const value = Number(amount);
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: value < 1 ? 4 : 2,
    }).format(value);
};

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
const webSearch = ref(false);
const deepThinking = ref(false);

const showSettings = ref(false);

// ── Переписка ──────────────────────────────────────────
const chatId = ref(props.chat?.id ?? null);
const messages = ref([...props.messages]);
const scroller = ref(null);

// Переход между чатами (или на «Новый чат») — тот же компонент с новыми props
watch(() => props.messages, (value) => {
    closeStream();
    chatId.value = props.chat?.id ?? null;
    messages.value = [...value];
    scrollToBottom(false);
});

const scrollToBottom = async (smooth = true) => {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
};

// ── Ввод ───────────────────────────────────────────────
const message = ref('');
const textarea = ref(null);
const busy = ref(false);
const error = ref(null);

watch(message, async () => {
    await nextTick();
    const el = textarea.value;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
});

const send = async () => {
    const content = message.value.trim();
    if (!content || busy.value) return;

    error.value = null;
    busy.value = true;
    thinking.value = true;

    messages.value.push({ id: `local-${crypto.randomUUID()}`, role: 'user', status: 'done', content });
    const local = messages.value.length - 1;
    message.value = '';
    scrollToBottom();

    let started;
    try {
        started = await postJson('/text/messages', {
            chat_id: chatId.value,
            ai_model_id: modelId.value,
            content,
            max_tokens: maxTokens.value,
            reasoning_effort: deepThinking.value ? effort.value : null,
        });
    } catch (e) {
        messages.value.splice(local, 1);
        message.value = content;
        error.value = e.message;
        busy.value = false;
        thinking.value = false;
        return;
    }

    messages.value[local] = started.user_message;
    if (!chatId.value) {
        chatId.value = started.chat.id;
        window.history.replaceState(window.history.state, '', `/text/${started.chat.id}`);
    }

    messages.value.push({ ...started.assistant_message, status: 'streaming' });
    stream(started.stream_token, messages.value[messages.value.length - 1]);
};

// ── Стрим ответа из Go-гейтвея ─────────────────────────
let source = null;

const closeStream = () => {
    source?.close();
    source = null;
    busy.value = false;
    thinking.value = false;
};

const stream = (token, answer) => {
    let finished = false;
    source = new EventSource(`/gw/stream?token=${encodeURIComponent(token)}`);

    // Огонь горит всю генерацию — от отправки до конца стрима (гасит closeStream)
    source.addEventListener('delta', (event) => {
        answer.content += JSON.parse(event.data).text;
        scrollToBottom(false);
    });

    source.addEventListener('done', (event) => {
        finished = true;
        Object.assign(answer, JSON.parse(event.data).message);
        closeStream();
        scrollToBottom();
        router.reload({ only: ['recentChats'] });
    });

    source.addEventListener('fail', (event) => {
        finished = true;
        Object.assign(answer, { status: 'failed', error: JSON.parse(event.data).message });
        closeStream();
    });

    // Обрыв соединения: гейтвей допишет ответ сам, он появится в чате после обновления
    source.onerror = () => {
        if (!finished) {
            Object.assign(answer, { status: 'failed', error: 'Соединение прервалось. Ответ сохранится в чате — обновите страницу через минуту.' });
        }
        closeStream();
    };
};

onBeforeUnmount(closeStream);

const onKeydown = (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
};
</script>

<template>
    <Head title="Текст" />

    <div class="flex min-h-0 flex-1 gap-6">
        <!-- Колонка чата: узкая, как у Anthropic. Правая панель шире левой, поэтому центр между ними
             левее центра экрана — компенсируем отступом, но только из лишнего места, не сужая колонку
             (cqw — ширина самой области чата) -->
        <div class="@container flex min-w-0 flex-1 justify-center">
            <div class="flex min-h-0 w-full max-w-3xl flex-1 flex-col gap-3 xl:ml-[min(6rem,max(0px,100cqw_-_48rem))]">
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
                <div v-else class="glass flex min-h-0 flex-1 flex-col overflow-hidden rounded-[28px]">
                    <div ref="scroller" class="scroll-fade scrollbar-glass flex-1 space-y-6 overflow-y-auto px-6 py-8">
                        <template v-for="m in messages" :key="m.id">
                            <div v-if="m.role === 'user'" class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl rounded-br-md bg-(--bubble-bg) px-4 py-2.5 text-[16px] leading-relaxed whitespace-pre-wrap">
                                    {{ m.content }}
                                </div>
                            </div>
                            <div v-else class="answer-frame space-y-2 px-4 py-3">
                                <div v-if="m.model" class="flex items-center gap-2 text-xs text-white/50">
                                    <VendorIcon :slug="m.model.vendor" />
                                    {{ m.model.name }}
                                </div>
                                <MarkdownContent v-if="m.content" :content="m.content" />
                                <div v-else-if="m.status === 'streaming' || m.status === 'pending'" class="flex items-center gap-2 text-sm text-hot-soft/80">
                                    <Sparkle class="size-4 animate-pulse fill-current" :stroke-width="0" />
                                    Думаю…
                                </div>
                                <p v-if="m.status === 'failed'" class="text-sm text-hot-soft">{{ m.error || 'Модель не ответила' }}</p>
                                <div v-if="m.cost" class="flex justify-end">
                                    <span class="text-xs text-white/55" title="Себестоимость ответа">{{ formatCost(m.cost) }}</span>
                                </div>
                            </div>
                        </template>

                    </div>
                </div>

                <p v-if="error" class="px-4 text-sm text-hot-soft">{{ error }}</p>

                <!-- Поле ввода -->
                <form class="glass flex items-end gap-3 rounded-[28px] px-4 py-3" @submit.prevent="send">
                    <button type="button" class="p-2 text-white/60 hover:text-white" title="Прикрепить файл (скоро)" disabled>
                        <Paperclip class="size-5" :stroke-width="1.5" />
                    </button>
                    <textarea
                        ref="textarea"
                        v-model="message"
                        rows="1"
                        placeholder="Написать сообщение…"
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
            </div>
        </div>

        <!-- Настройки на узких экранах открываются кнопкой -->
        <button
            type="button"
            class="glass absolute top-0 right-0 z-30 rounded-2xl p-3 xl:hidden"
            title="Настройки модели"
            @click="showSettings = !showSettings"
        >
            <SlidersHorizontal class="size-5" :stroke-width="1.5" />
        </button>

        <!-- Панель настроек: на широких экранах — своя колонка справа, чтобы не перекрывать ленту -->
        <section
            class="glass w-[min(24rem,calc(100vw-2rem))] shrink-0 self-start rounded-3xl p-4 xl:static xl:block"
            :class="showSettings ? 'absolute top-16 right-0 z-20 block' : 'hidden'"
        >
            <!-- Вендоры -->
            <div class="grid grid-cols-4 gap-1">
                <button
                    v-for="v in vendors"
                    :key="v.slug"
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-2xl border px-2 py-2 text-sm transition"
                    :class="v.slug === vendorSlug
                        ? 'border-cool/60 bg-cool/20 text-cool-soft shadow-[0_0_16px_rgba(91,140,255,0.35)]'
                        : 'border-transparent text-white/80 hover:bg-white/5'"
                    @click="vendorSlug = v.slug"
                >
                    <VendorIcon :slug="v.slug" />
                    {{ v.product }}
                </button>
            </div>

            <!-- Модель вендора -->
            <div class="relative mt-3">
                <button
                    type="button"
                    class="glass-inset flex w-full items-center justify-between rounded-2xl px-4 py-2.5 text-left"
                    @click="modelMenu = !modelMenu"
                >
                    <span>
                        <span class="block text-[15px]">{{ model?.name }}</span>
                        <span class="block text-xs text-white/50">{{ model?.description }}</span>
                    </span>
                    <ChevronDown class="size-4 text-white/60 transition" :class="{ 'rotate-180': modelMenu }" :stroke-width="1.5" />
                </button>

                <div v-if="modelMenu" class="fixed inset-0 z-10" @click="modelMenu = false" />
                <ul
                    v-if="modelMenu"
                    class="absolute inset-x-0 top-full z-20 mt-2 overflow-hidden rounded-2xl border border-white/10 bg-[#2c3445]/95 p-1 shadow-2xl backdrop-blur-xl"
                >
                    <li v-for="m in vendor.models" :key="m.id">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left hover:bg-white/5"
                            @click="modelId = m.id; modelMenu = false"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block text-[15px]">{{ m.name }}</span>
                                <span class="block text-xs text-white/50">
                                    {{ formatPrice(m.price_input, m.price_currency) }} / {{ formatPrice(m.price_output, m.price_currency) }} за 1M токенов
                                </span>
                            </span>
                            <Check v-if="m.id === modelId" class="size-4 text-cool-soft" :stroke-width="1.5" />
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Режимы (скоро) -->
            <div class="mt-3 grid grid-cols-2 gap-1.5">
                <button type="button" disabled class="flex items-center justify-center gap-2 rounded-2xl bg-cool/10 px-3 py-2 text-[13px] whitespace-nowrap text-cool-soft/60" title="Скоро">
                    <Users class="size-4" :stroke-width="1.5" />
                    Консилиум моделей
                </button>
                <button type="button" disabled class="flex items-center justify-center gap-2 rounded-2xl bg-white/5 px-3 py-2 text-[13px] whitespace-nowrap text-white/40" title="Скоро">
                    <Zap class="size-4" :stroke-width="1.5" />
                    Мульти-запрос
                </button>
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
                <GlassToggle v-model="webSearch" :icon="Globe" label="Web Search" disabled title="Скоро" />
                <GlassToggle v-model="deepThinking" :icon="Brain" label="Deep Thinking" />
            </div>
        </section>
    </div>
</template>
