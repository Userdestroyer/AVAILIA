<script setup>
import { Sparkle } from 'lucide-vue-next';
import MarkdownContent from '@/components/MarkdownContent.vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { formatCost } from '@/lib/format';

defineProps({
    answer: { type: Object, required: true },
});
</script>

<template>
    <div class="answer-frame space-y-2 px-4 py-3">
        <div v-if="answer.model" class="flex items-center gap-2 text-xs text-white/50">
            <VendorIcon :slug="answer.model.vendor" />
            {{ answer.model.name }}
        </div>
        <MarkdownContent v-if="answer.content" :content="answer.content" />
        <div v-else-if="answer.status === 'streaming' || answer.status === 'pending'" class="flex items-center gap-2 text-sm text-hot-soft/80">
            <Sparkle class="size-4 animate-pulse fill-current" :stroke-width="0" />
            Думаю…
        </div>
        <p v-if="answer.status === 'failed'" class="text-sm text-hot-soft">{{ answer.error || 'Модель не ответила' }}</p>
        <div v-if="answer.cost" class="flex justify-end">
            <span class="text-xs text-white/55" title="Себестоимость ответа">{{ formatCost(answer.cost) }}</span>
        </div>
    </div>
</template>
