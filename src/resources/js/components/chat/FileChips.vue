<script setup>
import { FileText, Loader2, X } from 'lucide-vue-next';

/**
 * Файлы в поле ввода: превью сразу (из памяти браузера), пока сжимаются и грузятся.
 */
defineProps({
    items: { type: Array, required: true },
});

defineEmits(['remove']);
</script>

<template>
    <template v-for="f in items" :key="f.key">
        <span v-if="f.kind === 'image'" class="relative size-10 shrink-0 overflow-hidden rounded-lg border border-fg/20" :title="f.name">
            <img :src="f.preview ?? f.file?.thumb_url ?? f.file?.url" alt="" class="size-full object-cover transition" :class="{ 'opacity-50': f.status === 'uploading' }" />
            <Loader2 v-if="f.status === 'uploading'" class="absolute inset-0 m-auto size-4 animate-spin text-white" :stroke-width="2" />
            <button type="button" class="absolute top-0.5 right-0.5 rounded-full bg-black/45 p-0.5 text-white" title="Убрать" @click="$emit('remove', f.key)">
                <X class="size-2.5" :stroke-width="2.5" />
            </button>
        </span>
        <span v-else class="glass-inset flex items-center gap-1 rounded-full py-0.5 pr-0.5 pl-1.5 text-xs text-fg/75" :title="f.name">
            <Loader2 v-if="f.status === 'uploading'" class="size-3.5 animate-spin text-cool-soft" :stroke-width="1.5" />
            <FileText v-else class="size-3.5 text-cool-soft" :stroke-width="1.5" />
            <span class="max-w-40 truncate">{{ f.name }}</span>
            <button type="button" class="rounded-full p-0.5 text-fg/40 hover:text-fg" title="Убрать" @click="$emit('remove', f.key)">
                <X class="size-3" :stroke-width="2" />
            </button>
        </span>
    </template>
</template>
