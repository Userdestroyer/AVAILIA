<script setup>
import { Check, FileText, Sparkles, Upload, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ACCEPT } from '@/lib/files';
import { library, loadLibrary } from '@/composables/useAttachments';

/**
 * Файловое поле: загруженное и сгенерированное. Клик по файлу — приложить к вопросу или убрать.
 * Отдельный чанк: грузится по первому нажатию на скрепку.
 */
const open = defineModel('open', { type: Boolean, default: false });
const props = defineProps({
    attachedIds: { type: Array, default: () => [] },
});
const emit = defineEmits(['toggle', 'upload']);

const TABS = [
    { key: 'uploaded', label: 'Загруженное', icon: Upload },
    { key: 'generated', label: 'Сгенерированное', icon: Sparkles },
];
const tab = ref('uploaded');
const input = ref(null);

const files = computed(() => library.files.filter((f) => f.source === tab.value));
const attached = computed(() => new Set(props.attachedIds));

const pick = (event) => {
    emit('upload', event.target.files);
    event.target.value = '';
};

const onKeydown = (event) => event.key === 'Escape' && (open.value = false);
onMounted(() => {
    loadLibrary();
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/25 p-2 backdrop-blur-sm md:p-4" @click.self="open = false">
                <section class="glass flex max-h-[calc(100dvh-1rem)] w-full max-w-2xl flex-col rounded-3xl md:max-h-[80dvh]" role="dialog" aria-modal="true" aria-label="Файлы">
                    <header class="flex flex-wrap items-center gap-2 p-3 md:gap-3 md:p-4">
                        <div class="glass-inset flex rounded-2xl p-1">
                            <button
                                v-for="item in TABS"
                                :key="item.key"
                                type="button"
                                class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm transition"
                                :class="tab === item.key ? 'pill-active' : 'text-fg/60 hover:text-fg'"
                                @click="tab = item.key"
                            >
                                <component :is="item.icon" class="size-4" :stroke-width="1.5" />
                                {{ item.label }}
                            </button>
                        </div>
                        <button type="button" class="glass-accent ml-auto flex items-center gap-2 rounded-xl px-3 py-2 text-sm" @click="input.click()">
                            <Upload class="size-4" :stroke-width="1.5" />
                            Загрузить новый
                        </button>
                        <input ref="input" type="file" class="hidden" multiple :accept="ACCEPT.join(',')" @change="pick" />
                        <button type="button" class="vein rounded-xl p-2 hover:text-fg" title="Закрыть" @click="open = false">
                            <X class="size-5" :stroke-width="1.5" />
                        </button>
                    </header>

                    <div class="scrollbar-glass min-h-0 flex-1 overflow-y-auto px-3 pb-3 md:px-4 md:pb-4">
                        <!-- Галерея ещё едет: каркас плиток на месте -->
                        <div v-if="!library.loaded && !files.length" class="grid grid-cols-3 gap-2 p-0.5 md:grid-cols-5">
                            <div v-for="n in 10" :key="n" class="glass-inset aspect-square animate-pulse rounded-xl" />
                        </div>

                        <div v-else-if="files.length" class="grid grid-cols-3 gap-2 p-0.5 md:grid-cols-5">
                            <button
                                v-for="file in files"
                                :key="file.id"
                                type="button"
                                class="glass-inset group relative aspect-square overflow-hidden rounded-xl transition"
                                :class="attached.has(file.id) ? 'ring-2 ring-(--accent-hi)' : 'hover:border-fg/40'"
                                :title="file.name"
                                @click="emit('toggle', file)"
                            >
                                <img
                                    v-if="file.kind === 'image'"
                                    :src="file.thumb_url ?? file.url"
                                    :alt="file.name"
                                    loading="lazy"
                                    decoding="async"
                                    class="size-full object-cover"
                                />
                                <span v-else class="flex size-full flex-col items-center justify-center gap-1.5 p-2 text-fg/70">
                                    <FileText class="size-7 text-cool-soft" :stroke-width="1.25" />
                                    <span class="line-clamp-2 text-[11px] break-all">{{ file.name }}</span>
                                </span>
                                <span v-if="attached.has(file.id)" class="absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full pill-active">
                                    <Check class="size-3" :stroke-width="2.5" />
                                </span>
                            </button>
                        </div>

                        <p v-else-if="tab === 'generated'" class="px-1 py-10 text-center text-sm text-fg/50">
                            Здесь появятся картинки и файлы, которые создадут модели.
                        </p>
                        <p v-else class="px-1 py-10 text-center text-sm text-fg/50">Загруженных файлов пока нет.</p>
                    </div>

                    <footer class="flex items-center gap-3 border-t border-fg/10 px-4 py-3 text-xs text-fg/50">
                        <span class="min-w-0 flex-1">Перетащите файлы в окно или вставьте скриншот (Ctrl+V) — прикрепятся сразу. Картинки и PDF.</span>
                        <button type="button" class="pill-active shrink-0 rounded-xl px-4 py-1.5 text-sm" @click="open = false">
                            Готово<template v-if="attachedIds.length"> · {{ attachedIds.length }}</template>
                        </button>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
