<script setup>
import { useTheme } from '@/composables/useTheme';

// JIVA — шрифт акцентов разрядкой со свечением; classic — исходный двухцветный градиент
const { style } = useTheme();
</script>

<template>
    <span v-if="style === 'jiva'" class="brand font-light text-fg [text-shadow:0_0_18px_rgb(91_140_255/0.45)]">Availia</span>
    <span v-else class="font-extralight tracking-[0.3em]">
        <span class="bg-gradient-to-r from-cool-soft to-cool bg-clip-text text-transparent">AVAI</span><span
            class="bg-gradient-to-r from-hot to-hot-soft bg-clip-text text-transparent"
        >LIA</span>
    </span>
</template>
