<script setup>
import { Head } from '@inertiajs/vue3';
import { AudioLines, Image, Video } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import ModeShell from '@/components/ModeShell.vue';

defineOptions({ layout: AppLayout });

defineProps({
    mode: { type: String, required: true },
});

const MODES = {
    images: { title: 'Изображения', icon: Image, text: 'Генерация и редактирование картинок' },
    video: { title: 'Видео', icon: Video, text: 'Генерация видео по тексту и картинке' },
    audio: { title: 'Аудио', icon: AudioLines, text: 'Озвучка, распознавание и музыка' },
};
</script>

<template>
    <Head :title="MODES[mode].title" />

    <ModeShell>
        <div class="flex flex-1 flex-col items-center justify-center px-4 text-center">
            <component :is="MODES[mode].icon" class="size-12 text-cool-soft" :stroke-width="1" />
            <h1 class="mt-6 text-4xl font-extralight tracking-tight text-white/90">{{ MODES[mode].title }}</h1>
            <p class="mt-4 text-lg font-light text-white/60">{{ MODES[mode].text }} — скоро</p>
        </div>
    </ModeShell>
</template>
