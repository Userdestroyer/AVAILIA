<script setup>
import { siClaude, siGooglegemini } from 'simple-icons';
import { computed } from 'vue';

const props = defineProps({
    slug: { type: String, required: true },
});

// Логотипов OpenAI и xAI в simple-icons нет — для них монограмма
const ICONS = {
    anthropic: { path: siClaude.path, color: '#D97757' },
    google: { path: siGooglegemini.path, color: '#8E75FF' },
};

const MONOGRAMS = {
    openai: 'AI',
    xai: 'X',
};

const icon = computed(() => ICONS[props.slug]);
</script>

<template>
    <svg v-if="icon" viewBox="0 0 24 24" class="size-4 shrink-0" :style="{ fill: icon.color }" aria-hidden="true">
        <path :d="icon.path" />
    </svg>
    <span
        v-else
        class="flex size-4 shrink-0 items-center justify-center rounded-full bg-white/85 text-[7px] font-semibold text-zinc-900"
        aria-hidden="true"
    >
        {{ MONOGRAMS[slug] ?? slug.charAt(0).toUpperCase() }}
    </span>
</template>
