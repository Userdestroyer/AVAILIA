<script setup>
import { Check, Copy, Trash2 } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';
import { copyText } from '@/lib/clipboard';

/**
 * Действия под сообщением: скопировать целиком и удалить (вторым кликом — как у чатов).
 */
const props = defineProps({
    text: { type: String, default: '' },
    deletable: { type: Boolean, default: true },
});

const emit = defineEmits(['delete']);

const copied = ref(false);
const confirming = ref(false);
let timer = 0;

const copy = async () => {
    try {
        await copyText(props.text);
        copied.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => (copied.value = false), 1200);
    } catch {
        // Буфер недоступен — молча, текст остаётся на экране
    }
};

const remove = () => {
    if (!confirming.value) {
        confirming.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => (confirming.value = false), 3000);
        return;
    }
    confirming.value = false;
    emit('delete');
};

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex items-center gap-0.5">
        <button
            type="button"
            class="rounded-lg p-1.5 text-white/40 transition hover:bg-white/5 hover:text-white"
            :title="copied ? 'Скопировано' : 'Скопировать'"
            :disabled="!text"
            @click="copy"
        >
            <Check v-if="copied" class="size-3.5 text-emerald-300" :stroke-width="2" />
            <Copy v-else class="size-3.5" :stroke-width="1.5" />
        </button>
        <button
            v-if="deletable"
            type="button"
            class="flex items-center gap-1 rounded-lg p-1.5 text-xs transition"
            :class="confirming ? 'bg-hot/20 px-2 text-hot-soft' : 'text-white/40 hover:bg-white/5 hover:text-hot-soft'"
            :title="confirming ? 'Нажмите ещё раз, чтобы удалить' : 'Удалить'"
            @click="remove"
        >
            <Trash2 class="size-3.5" :stroke-width="1.5" />
            <span v-if="confirming">Удалить?</span>
        </button>
    </div>
</template>
