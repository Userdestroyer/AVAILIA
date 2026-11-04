<script setup>
import { Check, ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { formatPrice } from '@/lib/format';

const props = defineProps({
    vendors: { type: Array, required: true },
    // Уже выбраны в соседних слотах мульти-запроса
    disabledIds: { type: Array, default: () => [] },
});

const modelId = defineModel({ type: Number, required: true });
const open = ref(false);

const current = computed(() => {
    for (const vendor of props.vendors) {
        const model = vendor.models.find((m) => m.id === modelId.value);
        if (model) return { ...model, vendor };
    }
    return null;
});

const pick = (id) => {
    modelId.value = id;
    open.value = false;
};
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="glass-inset flex w-full items-center gap-3 rounded-2xl px-4 py-2.5 text-left"
            @click="open = !open"
        >
            <VendorIcon v-if="current" :slug="current.vendor.slug" />
            <span class="min-w-0 flex-1">
                <span class="block truncate text-[15px]">{{ current?.name ?? 'Выберите модель' }}</span>
                <span class="block truncate text-xs text-white/50">{{ current?.vendor.product }} · {{ current?.description }}</span>
            </span>
            <ChevronDown class="size-4 shrink-0 text-white/60 transition" :class="{ 'rotate-180': open }" :stroke-width="1.5" />
        </button>

        <div v-if="open" class="fixed inset-0 z-10" @click="open = false" />
        <div
            v-if="open"
            class="scrollbar-glass absolute inset-x-0 top-full z-20 mt-2 max-h-[60vh] overflow-y-auto rounded-2xl border border-white/10 bg-[#2c3445]/95 p-1 shadow-2xl backdrop-blur-xl"
        >
            <section v-for="vendor in vendors" :key="vendor.slug">
                <h4 class="flex items-center gap-2 px-3 pt-2.5 pb-1 text-xs text-white/45">
                    <VendorIcon :slug="vendor.slug" />
                    {{ vendor.product }}
                </h4>
                <button
                    v-for="m in vendor.models"
                    :key="m.id"
                    type="button"
                    :disabled="disabledIds.includes(m.id)"
                    class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left hover:bg-white/5 disabled:cursor-default disabled:opacity-35 disabled:hover:bg-transparent"
                    @click="pick(m.id)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-[15px]">{{ m.name }}</span>
                        <span class="block text-xs text-white/50">
                            {{ formatPrice(m.price_input, m.price_currency) }} / {{ formatPrice(m.price_output, m.price_currency) }} за 1M токенов
                        </span>
                    </span>
                    <Check v-if="m.id === modelId" class="size-4 text-cool-soft" :stroke-width="1.5" />
                </button>
            </section>
        </div>
    </div>
</template>
