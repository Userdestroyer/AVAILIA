<script setup>
import { Check, Globe, LoaderCircle, Sparkle, X } from 'lucide-vue-next';
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
        <!-- Поиски модели: строчка на каждый запрос со статусом -->
        <ul v-if="answer.searches?.length" class="space-y-1">
            <li
                v-for="search in answer.searches"
                :key="search.id"
                class="glass-inset flex w-fit max-w-full items-center gap-2 rounded-full py-1 pr-3 pl-2 text-xs text-white/70"
            >
                <LoaderCircle v-if="search.status === 'searching'" class="size-3.5 shrink-0 animate-spin text-cool-soft" :stroke-width="2" />
                <Check v-else-if="search.status === 'done'" class="size-3.5 shrink-0 text-emerald-300" :stroke-width="2" />
                <X v-else class="size-3.5 shrink-0 text-hot-soft" :stroke-width="2" />
                <Globe class="size-3.5 shrink-0 text-white/40" :stroke-width="1.5" />
                <span class="truncate">{{ search.query || 'Ищу в интернете…' }}</span>
            </li>
        </ul>

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
