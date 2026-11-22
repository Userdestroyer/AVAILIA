<script setup>
import { Download, Gem, Sparkle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import MessageActions from '@/components/chat/MessageActions.vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { openLightbox } from '@/composables/useLightbox';
import { formatCost } from '@/lib/format';

/**
 * Ответ-картинка: пока рисуется — рамка в пропорциях заказа с таймером, готово — картинки (клик — во весь экран).
 */
const props = defineProps({
    answer: { type: Object, required: true },
});

defineEmits(['delete']);

const drawing = computed(() => ['pending', 'streaming'].includes(props.answer.status));

// Рамка-заготовка в тех пропорциях, что заказаны: картинка встанет на её место без скачка
const ratio = computed(() => {
    const [w, h] = String(props.answer.image?.params?.aspect_ratio ?? '1:1').split(':').map(Number);
    return w && h ? `${w} / ${h}` : '1 / 1';
});

// Секунды с начала — чтобы ожидание не казалось зависанием
const seconds = ref(0);
let timer = 0;
watch(drawing, (now) => {
    clearInterval(timer);
    if (!now) return;
    const started = props.answer.created_at ? new Date(props.answer.created_at).getTime() : Date.now();
    const tick = () => (seconds.value = Math.max(0, Math.round((Date.now() - started) / 1000)));
    tick();
    timer = setInterval(tick, 1000);
}, { immediate: true });
onBeforeUnmount(() => clearInterval(timer));

const view = (index) => openLightbox(props.answer.files, index);
</script>

<template>
    <div class="answer-frame space-y-2.5 px-2.5 py-2.5 md:px-4 md:py-3">
        <div v-if="drawing" class="glass-inset relative flex max-h-[28rem] w-full max-w-sm items-center justify-center overflow-hidden rounded-2xl" :style="{ aspectRatio: ratio }">
            <div class="absolute inset-0 animate-pulse bg-gradient-to-br from-cool/10 via-transparent to-cool/15" />
            <span class="relative flex items-center gap-2 text-sm text-fg/60">
                <Sparkle class="size-4 animate-pulse fill-current text-cool-soft" :stroke-width="0" />
                Рисую… {{ seconds }} с
            </span>
        </div>

        <div v-else-if="answer.files?.length" class="flex flex-wrap gap-2">
            <button
                v-for="(file, index) in answer.files"
                :key="file.id"
                type="button"
                class="overflow-hidden rounded-2xl border border-fg/15 transition hover:border-fg/40"
                :title="file.name"
                @click="view(index)"
            >
                <img
                    :src="file.url"
                    :alt="file.name"
                    :width="file.width"
                    :height="file.height"
                    loading="lazy"
                    decoding="async"
                    class="max-h-[28rem] w-auto max-w-full object-contain"
                    :style="file.thumb_url ? { backgroundImage: `url(${file.thumb_url})`, backgroundSize: 'cover' } : null"
                />
            </button>
        </div>

        <p v-if="answer.status === 'failed'" class="text-sm text-hot-soft">{{ answer.error || 'Модель не нарисовала' }}</p>

        <div class="-mb-1 flex flex-wrap items-center gap-x-2 gap-y-1">
            <MessageActions v-if="!drawing && Number.isInteger(answer.id)" :text="''" @delete="$emit('delete', answer)">
                <a
                    v-for="file in answer.files"
                    :key="file.id"
                    :href="file.url"
                    :download="file.name"
                    class="vein rounded-lg p-1.5 transition hover:text-fg"
                    title="Скачать"
                >
                    <Download class="size-3.5" :stroke-width="1.5" />
                </a>
            </MessageActions>
            <span v-if="answer.model" class="flex min-w-0 items-center gap-1.5 text-xs text-fg/45">
                <VendorIcon :slug="answer.model.vendor" />
                <span class="truncate">{{ answer.model.name }}</span>
            </span>
            <span
                v-if="answer.image?.tokens != null"
                class="ml-auto flex items-center gap-1 text-xs text-fg/55 tabular-nums"
                :title="answer.cost ? `Себестоимость: ${formatCost(answer.cost)}` : drawing ? 'Удержано по прайсу модели — пересчитается по факту' : ''"
            >
                <Gem class="size-3 text-cool-soft" :stroke-width="1.5" />{{ drawing ? '≈ ' : '' }}{{ answer.image.tokens }}
            </span>
        </div>
    </div>
</template>
