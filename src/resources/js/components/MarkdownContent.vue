<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { copyText } from '@/lib/clipboard';
import { renderMarkdown } from '@/lib/markdown';

const props = defineProps({
    content: { type: String, default: '' },
});

const html = ref(renderMarkdown(props.content));

// Во время стрима токены приходят чаще кадров: пересобираем разметку не чаще раза за кадр,
// иначе на длинных ответах парсинг на каждый токен начнёт тормозить ввод и скролл
let frame = 0;
watch(() => props.content, () => {
    if (frame) return;
    frame = requestAnimationFrame(() => {
        frame = 0;
        html.value = renderMarkdown(props.content);
    });
});

onBeforeUnmount(() => cancelAnimationFrame(frame));

// Копирование блока кода — один обработчик на весь ответ (кнопки пересоздаются при каждой пересборке)
const copyCode = async (event) => {
    const button = event.target.closest('[data-copy-code]');
    const code = button?.parentElement.querySelector('code');
    if (!code) return;
    try {
        await copyText(code.innerText);
        button.dataset.copied = '';
        setTimeout(() => delete button.dataset.copied, 1200);
    } catch {
        // Буфер недоступен — код остаётся на экране
    }
};
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html — HTML прошёл через DOMPurify -->
    <div class="markdown prose prose-invert max-w-none" @click="copyCode" v-html="html" />
</template>
