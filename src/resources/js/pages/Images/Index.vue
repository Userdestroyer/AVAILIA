<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowUp, Gem, Paperclip, SlidersHorizontal } from 'lucide-vue-next';
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, watchEffect } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import DropOverlay from '@/components/chat/DropOverlay.vue';
import EmptyHero from '@/components/chat/EmptyHero.vue';
import FileChips from '@/components/chat/FileChips.vue';
import ImageAnswer from '@/components/chat/ImageAnswer.vue';
import QuestionBubble from '@/components/chat/QuestionBubble.vue';
import ModelPicker from '@/components/ModelPicker.vue';
import ModeShell from '@/components/ModeShell.vue';
import { loadLibrary, remember, useAttachments } from '@/composables/useAttachments';
import { useFileDrop } from '@/composables/useFileDrop';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { useThinking } from '@/composables/useThinking';
import { deleteJson, getJson, postJson } from '@/lib/http';
import { estimateImage } from '@/lib/imagePrice';

/**
 * Чат картинок: тот же чат, только модель отвечает картинкой — она же появляется в «Сгенерированном».
 * Референсы — из файлового поля (скрепка), перетаскиванием или Ctrl+V.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    vendors: { type: Array, required: true },
    chat: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
    fileLimits: { type: Object, required: true },
});

const page = usePage();
const thinking = useThinking();
const isWide = useMediaQuery('(min-width: 1280px)');
const isTouch = useMediaQuery('(pointer: coarse)');
const showSettings = ref(false);

// ── Модель и параметры (запоминаются в браузере) ───────
const allModels = computed(() => props.vendors.flatMap((v) => v.models.map((m) => ({ ...m, vendor: v.slug }))));
const STORAGE = 'availia.images';
const saved = (() => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE)) ?? {};
    } catch {
        return {};
    }
})();
const prefs = reactive({ model_id: null, params: {}, ...saved });
watch(prefs, () => {
    try {
        localStorage.setItem(STORAGE, JSON.stringify(prefs));
    } catch {
        // Хранилище недоступно — выбор живёт до перезагрузки
    }
}, { deep: true });

const exists = (id) => allModels.value.some((m) => m.id === id);
if (!exists(prefs.model_id)) prefs.model_id = exists(props.chat?.ai_model_id) ? props.chat.ai_model_id : allModels.value[0]?.id ?? null;
const model = computed(() => allModels.value.find((m) => m.id === prefs.model_id));

const PARAMS = { aspect_ratio: 'Пропорции', image_resolution: 'Разрешение', quality: 'Качество' };
const options = computed(() => Object.entries(PARAMS)
    .map(([key, label]) => ({ key, label, values: model.value?.parameters?.[key]?.values ?? [], fallback: model.value?.parameters?.[key]?.default }))
    .filter((option) => option.values.length));
// Выбранное, если модель так умеет, иначе её умолчание — как посчитает сервер
const params = computed(() => Object.fromEntries(options.value.map((o) => [o.key, o.values.includes(prefs.params[o.key]) ? prefs.params[o.key] : (o.fallback ?? o.values[0])])));
const pick = (key, value) => (prefs.params = { ...prefs.params, [key]: value });

// ── Референсы ──────────────────────────────────────────
const error = ref(null);
const FilePicker = defineAsyncComponent(() => import('@/components/chat/FilePicker.vue'));
const pickerOpen = ref(false);
const pickerUsed = ref(false);
watch(pickerOpen, (open) => open && (pickerUsed.value = true));

const attachments = useAttachments(props.fileLimits, (text) => (error.value = text));
const { items: fileItems, ids: fileIds, uploading } = attachments;
const references = computed(() => model.value?.references ?? 0);
const attachedFileIds = computed(() => fileItems.value.filter((i) => i.file).map((i) => i.file.id));

const addFiles = (files) => {
    const list = [...files];
    const images = list.filter((file) => file.type.startsWith('image/'));
    if (images.length < list.length) error.value = 'Референсы — только картинки.';
    if (!references.value) {
        error.value = `${model.value?.name ?? 'Модель'} не принимает референсы.`;
        return;
    }
    attachments.add(images);
};
const toggleFile = (file) => (file.kind === 'image' ? attachments.toggle(file) : (error.value = 'Референсы — только картинки.'));
const { dragging, onPaste } = useFileDrop(addFiles);
const tooManyReferences = computed(() => fileItems.value.length > references.value);

// ── Цена и баланс токенов контента (1 токен = 1 ₽ себестоимости) ──
const estimate = computed(() => {
    const rub = estimateImage(model.value?.pricing, params.value, fileIds.value.length);
    return rub === null ? null : Math.ceil(rub);
});
const balance = computed(() => page.props.usage?.tokens ?? 0);

// ── Лента ──────────────────────────────────────────────
const messages = ref([...props.messages]);
const chatId = ref(props.chat?.id ?? null);
const scroller = ref(null);
const isEmpty = computed(() => !messages.value.length);

const turns = computed(() => messages.value
    .filter((m) => m.role === 'user')
    .map((question) => ({ question, answers: messages.value.filter((m) => m.role === 'assistant' && m.parent_id === question.id) })));

const scrollToBottom = async (smooth = true) => {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
};

const drawing = computed(() => messages.value.some((m) => m.role === 'assistant' && ['pending', 'streaming'].includes(m.status)));
// Фон «думает», пока рисуется
watchEffect(() => (thinking.value = drawing.value));

// Пока рисуется — спрашиваем статус; готово — картинка в ленте и в «Сгенерированном», баланс обновлён
let pollTimer = 0;
const poll = () => {
    clearTimeout(pollTimer);
    const pending = messages.value.filter((m) => m.role === 'assistant' && ['pending', 'streaming'].includes(m.status) && Number.isInteger(m.id));
    if (!pending.length) return;
    pollTimer = setTimeout(async () => {
        let finished = false;
        await Promise.all(pending.map(async (answer) => {
            try {
                const { message } = await getJson(`/images/messages/${answer.id}`);
                Object.assign(answer, message);
                if (message.status === 'done' || message.status === 'failed') finished = true;
                if (message.status === 'done') message.files.forEach(remember);
            } catch {
                // Сеть моргнула — спросим в следующий раз
            }
        }));
        if (finished) {
            router.reload({ only: ['usage'] });
            scrollToBottom();
        }
        poll();
    }, 2500);
};
onMounted(() => {
    scrollToBottom(false);
    poll();
});
onBeforeUnmount(() => clearTimeout(pollTimer));

watch(() => props.messages, (value) => {
    messages.value = [...value];
    chatId.value = props.chat?.id ?? null;
    scrollToBottom(false);
    poll();
});

// ── Отправка ───────────────────────────────────────────
const prompt = ref('');
const textarea = ref(null);
const sending = ref(false);
let localSeq = 0;

watch(prompt, async () => {
    await nextTick();
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
});

const blocked = computed(() => {
    if (!model.value) return 'Модели картинок не включены';
    if (uploading.value) return 'Референс ещё загружается';
    if (tooManyReferences.value) return references.value ? `${model.value.name} берёт не больше ${references.value} референсов` : `${model.value.name} не принимает референсы`;
    return null;
});

const send = async () => {
    const content = prompt.value.trim();
    if (!content || sending.value || blocked.value) {
        if (blocked.value) error.value = blocked.value;
        return;
    }

    error.value = null;
    sending.value = true;
    const ids = fileIds.value;
    const seq = ++localSeq;
    // Вопрос и рамка будущей картинки — сразу, не дожидаясь сервера
    messages.value.push(
        { id: `local-q${seq}`, role: 'user', status: 'done', content, context: [], files: attachments.present() },
        {
            id: `local-a${seq}`,
            role: 'assistant',
            parent_id: `local-q${seq}`,
            status: 'pending',
            content: '',
            files: [],
            model: { name: model.value.name, vendor: model.value.vendor },
            image: { params: params.value, tokens: estimate.value },
            created_at: new Date().toISOString(),
        },
    );
    const localFiles = messages.value.at(-2).files;
    const taken = attachments.take();
    prompt.value = '';
    scrollToBottom();

    let started;
    try {
        started = await postJson('/images/messages', { chat_id: chatId.value, ai_model_id: model.value.id, prompt: content, file_ids: ids, params: params.value });
    } catch (e) {
        messages.value = messages.value.filter((m) => m.id !== `local-q${seq}` && m.id !== `local-a${seq}`);
        prompt.value = content;
        attachments.restore(taken);
        error.value = e.message;
        return;
    } finally {
        sending.value = false;
    }

    const question = messages.value.findIndex((m) => m.id === `local-q${seq}`);
    messages.value[question] = {
        ...started.user_message,
        files: started.user_message.files.map((file) => localFiles.find((own) => own.id === file.id) ?? file),
    };
    messages.value[question + 1] = started.answer;
    if (!chatId.value) {
        chatId.value = started.chat.id;
        window.history.replaceState(window.history.state, '', `/images/${started.chat.id}`);
    }
    router.reload({ only: ['usage', 'recentChats'] });
    poll();
};

const onKeydown = (event) => {
    if (event.key !== 'Enter' || event.isComposing) return;
    if (event.ctrlKey || event.metaKey || (!isTouch.value && !event.shiftKey)) {
        event.preventDefault();
        send();
    }
};

// Убираем сразу, удаляем фоном; не вышло — возвращаем
const deleteMessage = async (message) => {
    const snapshot = [...messages.value];
    const gone = new Set([message.id]);
    if (message.role === 'user') messages.value.forEach((m) => m.parent_id === message.id && gone.add(m.id));
    messages.value = messages.value.filter((m) => !gone.has(m.id));
    try {
        await deleteJson(`/text/messages/${message.id}`);
    } catch (e) {
        messages.value = snapshot;
        error.value = e.message;
    }
};
</script>

<template>
    <Head title="Изображения" />

    <ModeShell>
        <div v-if="isEmpty" class="flex min-h-0 flex-1 flex-col items-center justify-end px-4 pb-6 text-center md:pb-10">
            <EmptyHero />
        </div>

        <div v-else v-frost class="glass relative flex min-h-0 flex-1 flex-col overflow-hidden rounded-[22px] md:rounded-[28px]">
            <div class="scroll-edge top-0" aria-hidden="true" />
            <div class="scroll-edge bottom-0 [--edge-to:top]" aria-hidden="true" />
            <div ref="scroller" class="scrollbar-glass flex-1 space-y-4 overflow-y-auto px-2 py-3 md:space-y-6 md:px-6 md:py-8">
                <template v-for="turn in turns" :key="turn.question.id">
                    <QuestionBubble
                        :question="turn.question"
                        :deletable="Number.isInteger(turn.question.id) && !turn.answers.some((a) => ['pending', 'streaming'].includes(a.status))"
                        @delete="deleteMessage(turn.question)"
                    />
                    <ImageAnswer v-for="answer in turn.answers" :key="answer.id" :answer="answer" @delete="deleteMessage" />
                </template>
            </div>
        </div>

        <p v-if="error" class="px-4 text-sm text-hot-soft">{{ error }}</p>

        <form v-frost class="glass flex flex-col rounded-[22px] px-2 py-1.5 md:rounded-[28px] md:px-4 md:py-3" @submit.prevent="send">
            <div v-if="fileItems.length" class="flex flex-wrap items-center gap-1 px-1 pb-1">
                <FileChips :items="fileItems" @remove="attachments.remove" />
                <span class="ml-auto pr-1 text-[11px] text-fg/35 tabular-nums" :class="{ 'text-hot-soft': tooManyReferences }">
                    {{ fileItems.length }} / {{ references }}
                </span>
            </div>

            <div class="flex items-end gap-1 md:gap-2">
                <button
                    type="button"
                    class="vein p-1.5 hover:text-fg md:p-2"
                    :title="references ? `Референсы — до ${references} картинок` : 'Эта модель не принимает референсы'"
                    @pointerenter="loadLibrary()"
                    @focus="loadLibrary()"
                    @click="pickerOpen = true"
                >
                    <Paperclip class="size-[18px] md:size-5" :stroke-width="1.5" />
                </button>
                <textarea
                    ref="textarea"
                    v-model="prompt"
                    rows="1"
                    placeholder="Опишите картинку…"
                    :enterkeyhint="isTouch ? 'enter' : 'send'"
                    class="max-h-[200px] flex-1 resize-none bg-transparent py-1.5 text-[15px] font-light outline-none placeholder:text-fg/45 md:py-2 md:text-[17px]"
                    @keydown="onKeydown"
                    @paste="onPaste"
                />
                <span
                    v-if="estimate !== null"
                    class="mb-2 flex shrink-0 items-center gap-1 text-xs text-fg/50 tabular-nums md:mb-2.5"
                    :class="{ 'text-hot-soft': estimate > balance }"
                    :title="`Примерно столько токенов контента спишется (1 токен = 1 ₽); на балансе ${balance}`"
                >
                    <Gem class="size-3.5 text-cool-soft" :stroke-width="1.5" />≈ {{ estimate }}
                </span>
                <button
                    type="submit"
                    :disabled="!prompt.trim() || sending || !!blocked"
                    class="flex size-9 shrink-0 items-center justify-center rounded-full pill-active transition disabled:opacity-40 md:size-11"
                    :title="blocked ?? 'Нарисовать'"
                >
                    <ArrowUp class="size-5" :stroke-width="2" />
                </button>
            </div>
        </form>

        <div v-if="isEmpty" class="flex min-h-0 flex-1 flex-col items-center px-4 pt-3 text-center">
            <p class="text-xs text-on-sky/70 text-shadow-(--text-shadow-sky) md:text-sm">
                Опишите картинку · <span class="text-on-sky/90">референсы</span> — скрепкой, перетаскиванием или Ctrl+V
            </p>
        </div>

        <FilePicker v-if="pickerUsed" v-model:open="pickerOpen" :attached-ids="attachedFileIds" @toggle="toggleFile" @upload="addFiles" />
        <DropOverlay :show="dragging" hint="Картинки — как референсы" />

        <template #actions>
            <button
                v-frost
                type="button"
                class="glass shrink-0 rounded-full p-2 md:p-2.5 xl:hidden"
                :class="{ 'ring-1 ring-fg/40': showSettings }"
                title="Настройки картинки"
                @click="showSettings = !showSettings"
            >
                <SlidersHorizontal class="vein size-5" :stroke-width="1.5" />
            </button>
        </template>

        <template #panel>
            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-200" leave-to-class="opacity-0">
                <div v-if="!isWide && showSettings" class="fixed inset-0 z-40 bg-black/30 backdrop-blur-md" @click="showSettings = false" />
            </Transition>

            <section
                v-frost="isWide"
                class="glass shrink-0 self-start rounded-3xl p-4"
                :class="isWide
                    ? 'w-[24rem]'
                    : ['scrollbar-glass fixed inset-x-4 top-[4.5rem] z-50 max-h-[calc(100dvh-6rem)] overflow-y-auto md:left-auto md:w-[24rem]', showSettings ? 'block' : 'hidden']"
            >
                <template v-if="model">
                    <ModelPicker v-model="prefs.model_id" :vendors="vendors" />

                    <div v-for="option in options" :key="option.key" class="mt-4">
                        <div class="mb-1.5 text-sm text-fg/70">{{ option.label }}</div>
                        <div class="glass-inset flex flex-wrap gap-0.5 rounded-2xl p-1">
                            <button
                                v-for="value in option.values"
                                :key="value"
                                type="button"
                                class="rounded-xl px-2.5 py-1.5 text-[13px] tabular-nums transition"
                                :class="params[option.key] === value ? 'pill-active' : 'text-fg/60 hover:text-fg'"
                                @click="pick(option.key, value)"
                            >
                                {{ value === 'auto' ? 'авто' : value }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 space-y-1.5 border-t border-fg/10 pt-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-fg/70">Референсы</span>
                            <span class="text-fg/55">{{ references ? `до ${references}` : 'не принимает' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-fg/70">Цена</span>
                            <span class="text-fg/55 tabular-nums">{{ estimate !== null ? `≈ ${estimate} ток.` : 'по факту' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="flex items-center gap-1.5 text-fg/70"><Gem class="size-3.5 text-cool-soft" :stroke-width="1.5" /> Токены контента</span>
                            <span class="text-fg/55 tabular-nums">{{ balance }}</span>
                        </div>
                    </div>
                </template>
                <p v-else class="text-sm text-fg/55">
                    Модели картинок ещё не включены. Админ: <span class="font-mono text-xs">models:import</span>, затем включить нужные в «Модели и цены».
                </p>
            </section>
        </template>
    </ModeShell>
</template>
