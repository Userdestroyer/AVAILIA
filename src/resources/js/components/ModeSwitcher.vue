<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { AudioLines, Image, MessageSquareText, Shield, Video } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage();

const modes = computed(() => [
    { href: '/text', label: 'Текст', icon: MessageSquareText },
    { href: '/images', label: 'Изображения', icon: Image },
    { href: '/video', label: 'Видео', icon: Video },
    { href: '/audio', label: 'Аудио', icon: AudioLines },
    ...(page.props.auth.user?.role === 'admin' ? [{ href: '/admin', label: 'Админ', icon: Shield }] : []),
]);

const isCurrent = (href) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);

// Пузырь едет к выбранному режиму сразу по клику — не дожидаясь ответа сервера
const active = ref(Math.max(0, modes.value.findIndex((mode) => isCurrent(mode.href))));
const items = ref([]);
const bubble = ref({ left: 0, width: 0 });
const animate = ref(false);

const place = () => {
    const el = items.value[active.value]?.$el;
    if (el) bubble.value = { left: el.offsetLeft, width: el.offsetWidth };
};

const choose = (index) => {
    animate.value = true;
    active.value = index;
    place();
};

onMounted(async () => {
    await nextTick();
    place();
    window.addEventListener('resize', place);
});
onBeforeUnmount(() => window.removeEventListener('resize', place));
</script>

<template>
    <nav class="flex justify-center">
        <div v-frost class="glass relative flex max-w-full gap-0.5 overflow-x-auto rounded-full p-1 scrollbar-none">
            <span
                class="pill-active pointer-events-none absolute top-1 bottom-1 rounded-full"
                :class="{ 'transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]': animate }"
                :style="{ left: `${bubble.left}px`, width: `${bubble.width}px` }"
            />
            <Link
                v-for="(mode, index) in modes"
                :key="mode.href"
                ref="items"
                :href="mode.href"
                :prefetch="['mount', 'hover']"
                class="relative flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[13px] whitespace-nowrap transition-colors md:gap-2 md:px-4 md:text-sm"
                :class="index === active ? 'text-(--pill-fg)' : 'text-fg/60 hover:text-fg'"
                @click="choose(index)"
            >
                <component :is="mode.icon" class="size-4" :class="{ vein: index !== active }" :stroke-width="1.5" />
                {{ mode.label }}
            </Link>
        </div>
    </nav>
</template>
