<script setup>
import { ChevronLeft, ChevronRight, Download, ExternalLink, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { closeLightbox, lightbox } from '@/composables/useLightbox';

/**
 * Картинка во весь экран: сразу — превью (уже в кэше браузера), поверх проявляется оригинал.
 */
const item = computed(() => lightbox.items[lightbox.index]);
const loaded = ref(false);
watch(item, () => (loaded.value = false));

const step = (delta) => {
    const count = lightbox.items.length;
    if (count > 1) lightbox.index = (lightbox.index + delta + count) % count;
};

const onKeydown = (event) => {
    if (!lightbox.open) return;
    if (event.key === 'Escape') closeLightbox();
    if (event.key === 'ArrowLeft') step(-1);
    if (event.key === 'ArrowRight') step(1);
};
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
            <div v-if="lightbox.open && item" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/80 p-3 backdrop-blur-md md:p-8" @click.self="closeLightbox">
                <div class="relative flex max-h-full max-w-full items-center justify-center">
                    <!-- Превью размыто под оригиналом, пока тот грузится -->
                    <img
                        v-if="item.thumb_url && !loaded"
                        :src="item.thumb_url"
                        alt=""
                        class="absolute inset-0 m-auto max-h-[86dvh] max-w-[92vw] scale-[1.01] rounded-2xl object-contain blur-sm"
                    />
                    <img
                        :key="item.url"
                        :src="item.url"
                        :alt="item.name"
                        class="relative max-h-[86dvh] max-w-[92vw] rounded-2xl object-contain shadow-2xl transition-opacity duration-300"
                        :class="loaded ? 'opacity-100' : 'opacity-0'"
                        @load="loaded = true"
                    />
                </div>

                <!-- Белое — на тёмной подложке поверх картинки, как значки -->
                <div class="absolute top-3 right-3 flex items-center gap-1 text-white md:top-5 md:right-5">
                    <a :href="item.url" :download="item.name" class="rounded-full bg-black/40 p-2.5 hover:bg-black/60" title="Скачать">
                        <Download class="size-5" :stroke-width="1.5" />
                    </a>
                    <a :href="item.url" target="_blank" rel="noopener" class="rounded-full bg-black/40 p-2.5 hover:bg-black/60" title="Открыть оригинал">
                        <ExternalLink class="size-5" :stroke-width="1.5" />
                    </a>
                    <button type="button" class="rounded-full bg-black/40 p-2.5 hover:bg-black/60" title="Закрыть (Esc)" @click="closeLightbox">
                        <X class="size-5" :stroke-width="1.5" />
                    </button>
                </div>

                <template v-if="lightbox.items.length > 1">
                    <button type="button" class="absolute left-2 rounded-full bg-black/40 p-2.5 text-white hover:bg-black/60 md:left-5" title="Предыдущая (←)" @click="step(-1)">
                        <ChevronLeft class="size-6" :stroke-width="1.5" />
                    </button>
                    <button type="button" class="absolute right-2 rounded-full bg-black/40 p-2.5 text-white hover:bg-black/60 md:right-5" title="Следующая (→)" @click="step(1)">
                        <ChevronRight class="size-6" :stroke-width="1.5" />
                    </button>
                    <span class="absolute bottom-4 rounded-full bg-black/40 px-3 py-1 text-xs text-white tabular-nums">{{ lightbox.index + 1 }} / {{ lightbox.items.length }}</span>
                </template>
            </div>
        </Transition>
    </Teleport>
</template>
