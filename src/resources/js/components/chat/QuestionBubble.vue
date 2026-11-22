<script setup>
import { ChevronsDownUp, ChevronsUpDown, Disc3 } from 'lucide-vue-next';
import { ref } from 'vue';
import MessageActions from '@/components/chat/MessageActions.vue';
import MessageFiles from '@/components/chat/MessageFiles.vue';
import { getJson } from '@/lib/http';

/**
 * Вопрос в ленте: отметка о приложенных к нему картриджах, файлы, пузырь, действия. Вопрос с картриджами
 * можно развернуть целиком — каким он ушёл в модель: каждый картридж в своей версии, под тонкой линией с подписью.
 */
const props = defineProps({
    question: { type: Object, required: true },
    deletable: { type: Boolean, default: false },
});

defineEmits(['delete']);

const expanded = ref(false);
const plates = ref(null);
const failed = ref(null);

// Содержимое картриджей грузится по наведению на кнопку — к клику оно обычно уже здесь
let loading = null;
const load = () => {
    loading ??= getJson(`/text/messages/${props.question.id}/context`)
        .then((data) => (plates.value = data.plates))
        .catch((e) => {
            loading = null;
            throw e;
        });
    return loading;
};

const toggle = async () => {
    expanded.value = !expanded.value;
    failed.value = null;
    if (!expanded.value || plates.value) return;
    try {
        await load();
    } catch (e) {
        failed.value = e.message;
    }
};

const canExpand = () => props.question.context?.length && Number.isInteger(props.question.id);
</script>

<template>
    <div class="group flex flex-col items-end gap-0.5">
        <!-- Картриджи приложены к этому вопросу: отметка в ленте — с этого места разговор идёт с ними -->
        <div v-if="question.context?.length" class="mb-1 flex w-full items-center gap-2 text-[11px] text-fg/45">
            <span class="h-px flex-1 bg-fg/10" />
            <Disc3 class="vein size-3 shrink-0" :stroke-width="1.5" />
            <span class="min-w-0 truncate">
                {{ question.context.length === 1 ? 'Подключён картридж' : 'Подключены картриджи' }}
                {{ question.context.map((item) => `«${item.title}»`).join(', ') }}
            </span>
            <span class="h-px flex-1 bg-fg/10" />
        </div>
        <MessageFiles v-if="question.files?.length" class="mb-0.5" :files="question.files" />
        <div
            v-if="question.content || expanded"
            class="max-w-[85%] rounded-2xl rounded-br-md bg-(--bubble-bg) px-3 py-2 text-[0.875rem] leading-relaxed md:px-4 md:py-2.5 md:text-[16px]"
        >
            <!-- Вопрос целиком: картриджи, каждый под своей подписью, и сам вопрос -->
            <template v-if="expanded">
                <p v-if="failed" class="mb-2 text-xs text-hot-soft">{{ failed }}</p>
                <p v-else-if="!plates" class="mb-2 text-xs text-fg/45">Загружаю картриджи…</p>
                <section v-for="plate in plates" :key="plate.plate_id" class="mb-2.5 border-b border-fg/15 pb-2.5">
                    <div class="mb-1 flex items-center gap-1 text-[11px] text-fg/45">
                        <Disc3 class="vein size-3" :stroke-width="1.5" />
                        {{ plate.title }}
                    </div>
                    <div class="text-[0.8125rem] leading-relaxed whitespace-pre-wrap text-fg/70 md:text-[14px]">{{ plate.content }}</div>
                </section>
            </template>
            <div class="whitespace-pre-wrap">{{ question.content }}</div>
        </div>
        <!-- На телефоне видны всегда, на десктопе — при наведении -->
        <MessageActions
            class="transition md:opacity-0 md:group-focus-within:opacity-100 md:group-hover:opacity-100"
            :class="{ 'md:opacity-100': expanded }"
            :text="question.content"
            :deletable="deletable"
            @delete="$emit('delete')"
        >
            <button
                v-if="canExpand()"
                type="button"
                class="vein rounded-lg p-1.5 transition hover:text-fg"
                :title="expanded ? 'Свернуть' : 'Показать вопрос целиком — с картриджами'"
                @pointerenter="load().catch(() => {})"
                @click="toggle"
            >
                <ChevronsDownUp v-if="expanded" class="size-3.5" :stroke-width="1.5" />
                <ChevronsUpDown v-else class="size-3.5" :stroke-width="1.5" />
            </button>
        </MessageActions>
    </div>
</template>
