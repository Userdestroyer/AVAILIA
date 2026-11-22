<script setup>
import { Check, ChevronLeft, ChevronRight, Globe, LoaderCircle, Sparkle, X, Zap } from 'lucide-vue-next';
import { computed } from 'vue';
import MarkdownContent from '@/components/MarkdownContent.vue';
import MessageActions from '@/components/chat/MessageActions.vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { formatCost, formatEnergy } from '@/lib/format';

const props = defineProps({
    answer: { type: Object, required: true },
    // Все варианты ответа этой модели на вопрос (регенерация), включая показанный
    variants: { type: Array, default: () => [] },
    // Идёт отправка или чей-то стрим — новый вариант не запросить
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['delete', 'select', 'regenerate']);

// Совпадает с MessageController::MAX_REGENERATIONS: исходный ответ и до 5 перегенераций
const MAX_VARIANTS = 6;

const writing = (answer) => ['pending', 'streaming'].includes(answer.status);
// Пока ответ пишется — удалить нельзя (гейтвей ещё отчитается по нему)
const finished = () => !writing(props.answer) && Number.isInteger(props.answer.id);

// ‹ 2/3 › — листать варианты; «вправо» на последнем — запросить ещё один
const list = computed(() => (props.variants.length ? props.variants : [props.answer]));
const index = computed(() => Math.max(0, list.value.findIndex((v) => v.id === props.answer.id)));
const isLast = computed(() => index.value === list.value.length - 1);
const canRegenerate = computed(() => finished() && !props.busy && list.value.length < MAX_VARIANTS && !list.value.some(writing));
const next = () => (isLast.value ? canRegenerate.value && emit('regenerate', props.answer) : emit('select', list.value[index.value + 1]));
const nextTitle = computed(() => {
    if (!isLast.value) return 'Следующий вариант';
    if (list.value.length >= MAX_VARIANTS) return 'Больше вариантов не получить';
    return 'Ещё вариант ответа';
});
</script>

<template>
    <div class="answer-frame space-y-2 px-2.5 py-2.5 md:px-4 md:py-3">
        <!-- Поиски модели: строчка на каждый запрос со статусом -->
        <ul v-if="answer.searches?.length" class="space-y-1">
            <li
                v-for="search in answer.searches"
                :key="search.id"
                class="glass-inset flex w-fit max-w-full items-center gap-2 rounded-full py-1 pr-3 pl-2 text-xs text-fg/70"
            >
                <LoaderCircle v-if="search.status === 'searching'" class="size-3.5 shrink-0 animate-spin text-cool-soft" :stroke-width="2" />
                <Check v-else-if="search.status === 'done'" class="size-3.5 shrink-0 text-emerald-300" :stroke-width="2" />
                <X v-else class="size-3.5 shrink-0 text-hot-soft" :stroke-width="2" />
                <Globe class="vein size-3.5 shrink-0" :stroke-width="1.5" />
                <span class="truncate">{{ search.query || 'Ищу в интернете…' }}</span>
            </li>
        </ul>

        <MarkdownContent v-if="answer.content" :content="answer.content" />
        <div v-else-if="answer.status === 'streaming' || answer.status === 'pending'" class="flex items-center gap-2 text-sm text-hot-soft/80">
            <Sparkle class="size-4 animate-pulse fill-current" :stroke-width="0" />
            Думаю…
        </div>
        <p v-if="answer.status === 'failed'" class="text-sm text-hot-soft">{{ answer.error || 'Модель не ответила' }}</p>
        <!-- Низ ответа: действия, варианты, модель и цена -->
        <div class="-mb-1 flex flex-wrap items-center gap-x-2 gap-y-1">
            <MessageActions v-if="finished()" :text="answer.content" @delete="emit('delete', answer)" />
            <div v-if="finished() || list.length > 1" class="flex items-center text-fg/55">
                <button
                    type="button"
                    class="vein rounded-lg p-1 transition hover:text-fg disabled:opacity-30"
                    title="Предыдущий вариант"
                    :disabled="index === 0"
                    @click="emit('select', list[index - 1])"
                >
                    <ChevronLeft class="size-4" :stroke-width="1.5" />
                </button>
                <span v-if="list.length > 1" class="min-w-7 text-center text-xs tabular-nums">{{ index + 1 }}/{{ list.length }}</span>
                <button
                    type="button"
                    class="vein rounded-lg p-1 transition hover:text-fg disabled:opacity-30"
                    :title="nextTitle"
                    :disabled="isLast && !canRegenerate"
                    @click="next"
                >
                    <ChevronRight class="size-4" :stroke-width="1.5" />
                </button>
            </div>
            <span v-if="answer.model" class="flex min-w-0 items-center gap-1.5 text-xs text-fg/45">
                <VendorIcon :slug="answer.model.vendor" />
                <span class="truncate">{{ answer.model.name }}</span>
            </span>
            <span
                v-if="answer.energy !== null && answer.energy !== undefined"
                class="ml-auto flex items-center gap-0.5 text-xs text-fg/55 tabular-nums"
                :title="answer.cost ? `Себестоимость: ${formatCost(answer.cost)}` : ''"
            >
                <Zap class="size-3 fill-current text-cool-soft" :stroke-width="0" />{{ formatEnergy(answer.energy) }}
            </span>
        </div>
    </div>
</template>
