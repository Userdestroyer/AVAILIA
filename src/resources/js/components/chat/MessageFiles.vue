<script setup>
import { FileText } from 'lucide-vue-next';

/**
 * Файлы, приложенные к вопросу: картинки превью, документы — чипом. Клик открывает оригинал.
 */
defineProps({
    files: { type: Array, required: true },
});
</script>

<template>
    <div class="flex max-w-[85%] flex-wrap justify-end gap-1.5">
        <a
            v-for="file in files"
            :key="file.id"
            :href="file.url"
            target="_blank"
            rel="noopener"
            :title="file.name"
            :class="file.kind === 'image' ? 'block overflow-hidden rounded-xl border border-fg/15' : 'glass-inset flex items-center gap-1.5 self-start rounded-xl px-2.5 py-1.5 text-xs text-fg/75 hover:text-fg'"
        >
            <img
                v-if="file.kind === 'image'"
                :src="file.thumb_url ?? file.url"
                :alt="file.name"
                :width="file.width"
                :height="file.height"
                loading="lazy"
                decoding="async"
                class="h-24 w-auto max-w-56 object-cover md:h-32 md:max-w-72"
            />
            <template v-else>
                <FileText class="size-4 shrink-0 text-cool-soft" :stroke-width="1.5" />
                <span class="max-w-48 truncate">{{ file.name }}</span>
            </template>
        </a>
    </div>
</template>
