<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AnswerCard from '@/components/chat/AnswerCard.vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { formatCost } from '@/lib/format';

/**
 * Ответы мульти-запроса: лента карточек со свайпом. Центральная — в фокусе,
 * соседние выглядывают по краям приглушённые и размытые и уходят под край стекла.
 */
const props = defineProps({
    answers: { type: Array, required: true },
});

defineEmits(['delete']);

const track = ref(null);
const cards = ref([]);
const active = ref(0);
// Высота ленты — по карточке в фокусе: длинный соседний ответ не растягивает ленту
const height = ref(null);

const fitHeight = () => {
    const card = cards.value[active.value];
    if (card) height.value = card.offsetHeight;
};

// Карточки растут во время стрима — подстраиваем высоту без опроса
const resizes = new ResizeObserver(fitHeight);
const observeCards = async () => {
    await nextTick();
    resizes.disconnect();
    cards.value.forEach((card) => resizes.observe(card));
    fitHeight();
};

onMounted(observeCards);
watch(() => props.answers.length, observeCards);
watch(active, fitHeight);

// Пока лента сама едет к карточке (клик по чипу), промежуточные позиции не меняют выбор
let programmatic = false;
let programmaticTimer = 0;

const go = (index) => {
    const card = cards.value[index];
    if (!card) return;

    active.value = index;
    programmatic = true;
    clearTimeout(programmaticTimer);
    programmaticTimer = setTimeout(() => (programmatic = false), 700);

    // offsetLeft считается от ленты (она relative) — центрируем карточку
    track.value.scrollTo({ left: card.offsetLeft - (track.value.clientWidth - card.offsetWidth) / 2, behavior: 'smooth' });
};

// Активная карточка — ближайшая к центру ленты; считаем не чаще раза за кадр
let frame = 0;
const onScroll = () => {
    if (programmatic || frame) return;
    frame = requestAnimationFrame(() => {
        frame = 0;
        const center = track.value.scrollLeft + track.value.clientWidth / 2;
        const distances = cards.value.map((card) => Math.abs(card.offsetLeft + card.offsetWidth / 2 - center));
        active.value = distances.indexOf(Math.min(...distances));
    });
};

const onScrollEnd = () => {
    programmatic = false;
};

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    clearTimeout(programmaticTimer);
    resizes.disconnect();
});
</script>

<template>
    <div class="space-y-2">
        <!-- Чипы моделей: где кто, кто ещё пишет и почём -->
        <div class="flex flex-wrap gap-1.5 px-[calc(6%+0.5rem)]">
            <button
                v-for="(answer, index) in answers"
                :key="answer.id"
                type="button"
                class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs transition"
                :class="index === active ? 'bg-white/12 text-white' : 'text-white/55 hover:text-white'"
                @click="go(index)"
            >
                <VendorIcon v-if="answer.model" :slug="answer.model.vendor" />
                {{ answer.model?.name }}
                <span v-if="answer.status === 'streaming' || answer.status === 'pending'" class="size-1.5 animate-pulse rounded-full bg-hot" />
                <span v-else-if="answer.cost" class="text-white/45">{{ formatCost(answer.cost) }}</span>
            </button>
        </div>

        <div
            ref="track"
            class="answer-track relative flex items-start gap-4 overflow-x-auto overflow-y-hidden scrollbar-none transition-[height] duration-300 ease-out"
            :style="height ? { height: `${height}px` } : null"
            @scroll="onScroll"
            @scrollend="onScrollEnd"
        >
            <div
                v-for="(answer, index) in answers"
                :key="answer.id"
                ref="cards"
                class="w-[88%] shrink-0 snap-center transition duration-300"
                :class="index === active ? '' : 'cursor-pointer opacity-35 blur-[1.5px] saturate-50'"
                @click="index !== active && go(index)"
            >
                <AnswerCard :answer="answer" @delete="$emit('delete', $event)" />
            </div>
        </div>
    </div>
</template>
