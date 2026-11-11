<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useTheme } from '@/composables/useTheme';

/**
 * Фон приложения — шейдер выбранного стиля. Каждый стиль — свой чанк (грузится, только когда выбран).
 * При смене стиля старый фон гаснет и уничтожается целиком (контекст WebGL отдаётся браузеру),
 * и только потом поднимается новый: два стиля никогда не держат видеопамять одновременно.
 */
const props = defineProps({
    // Модель думает или пишет — фон оживает
    thinking: { type: Boolean, default: false },
});

const LOADERS = {
    jiva: () => import('@/backgrounds/jiva.js'),
    classic: () => import('@/backgrounds/classic.js'),
};

const { style, theme } = useTheme();
// Чанк текущего стиля начинает грузиться сразу, вместе с приложением, а не после монтирования
LOADERS[style.value]?.();

const canvas = ref(null);
// Новый canvas на каждый стиль: у старого контекст отдан браузеру и больше не годится
const canvasKey = ref(0);
const visible = ref(false);
const FADE_MS = 200;

let handle = null;
let generation = 0;

const mount = async (name) => {
    const current = ++generation;
    const module = await LOADERS[name]();
    await nextTick();
    if (current !== generation || !canvas.value) return;

    handle = module.create(canvas.value, {
        getThinking: () => props.thinking,
        getTheme: () => theme.value,
        // Фон готов (у JIVA — запечён) — проявляем
        onReady: () => current === generation && requestAnimationFrame(() => (visible.value = true)),
    });
    // Шейдер рисует матовые подложки панелей сам — CSS снимает с них дорогой backdrop-filter.
    // Без WebGL атрибута нет, и панели размывает обычный CSS
    if (handle) document.documentElement.dataset.frost = 'gl';
    else delete document.documentElement.dataset.frost;
};

const unmount = () => {
    handle?.destroy();
    handle = null;
};

watch(style, async (name) => {
    generation++;
    visible.value = false;
    await new Promise((resolve) => setTimeout(resolve, FADE_MS));
    unmount();
    canvasKey.value++;
    mount(name);
});

// При reduced motion кадр статичный — перерисовываем по событию
watch([theme, () => props.thinking], () => handle?.redraw());

onMounted(() => mount(style.value));
onBeforeUnmount(() => {
    generation++;
    unmount();
    delete document.documentElement.dataset.frost;
});
</script>

<template>
    <!-- Во всю высоту экрана (lvh): клавиатура и адресная строка не сжимают фон -->
    <div class="pointer-events-none fixed inset-x-0 top-0 -z-10 h-lvh bg-(--app-bg) transition-colors duration-500">
        <canvas
            :key="canvasKey"
            ref="canvas"
            class="size-full transition-opacity ease-out"
            :class="visible ? 'opacity-100 duration-500' : 'opacity-0 duration-200'"
            aria-hidden="true"
        />
    </div>
</template>
