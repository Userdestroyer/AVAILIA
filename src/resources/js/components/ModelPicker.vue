<script setup>
import { Check, ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { formatPrice } from '@/lib/format';

/**
 * Выбор модели в одну строку: квадратная кнопка вендора (иконка) + модели этого вендора.
 * Компактно — в мульти-запросе слоты не толпятся.
 */
const props = defineProps({
    vendors: { type: Array, required: true },
    // Уже выбраны в соседних слотах мульти-запроса
    disabledIds: { type: Array, default: () => [] },
});

const modelId = defineModel({ type: Number, required: true });
const open = ref(null); // 'vendor' | 'model' | null

const vendor = computed(() => props.vendors.find((v) => v.models.some((m) => m.id === modelId.value)) ?? props.vendors[0]);
const model = computed(() => vendor.value?.models.find((m) => m.id === modelId.value));

const freeModels = (v) => v.models.filter((m) => !props.disabledIds.includes(m.id));

// Сменили вендора — сразу ставим его первую свободную модель
const pickVendor = (v) => {
    const first = freeModels(v)[0];
    if (first) modelId.value = first.id;
    open.value = null;
};

const pickModel = (id) => {
    modelId.value = id;
    open.value = null;
};

const toggle = (what) => (open.value = open.value === what ? null : what);
</script>

<template>
    <div class="relative flex gap-2">
        <!-- Вендор -->
        <button
            type="button"
            class="glass-inset vein-ring flex w-14 shrink-0 items-center justify-center rounded-2xl"
            :title="vendor?.product"
            @click="toggle('vendor')"
        >
            <VendorIcon v-if="vendor" :slug="vendor.slug" class="!size-5" />
            <ChevronDown class="vein absolute right-1.5 bottom-1.5 size-3" :stroke-width="2" />
        </button>

        <!-- Модель вендора -->
        <button
            type="button"
            class="glass-inset vein-ring flex min-w-0 flex-1 items-center gap-3 rounded-2xl px-4 py-2.5 text-left"
            @click="toggle('model')"
        >
            <span class="min-w-0 flex-1">
                <span class="block truncate text-[15px]">{{ model?.name ?? 'Выберите модель' }}</span>
                <span class="block truncate text-xs text-fg/50">{{ vendor?.product }} · {{ model?.description }}</span>
            </span>
            <ChevronDown class="vein size-4 shrink-0 transition" :class="{ 'rotate-180': open === 'model' }" :stroke-width="1.5" />
        </button>

        <div v-if="open" class="fixed inset-0 z-10" @click="open = null" />

        <!-- Список вендоров -->
        <div
            v-if="open === 'vendor'"
            class="absolute top-full left-0 z-20 mt-2 w-56 rounded-2xl border border-fg/10 bg-(--popover-bg) p-1 shadow-2xl backdrop-blur-xl"
        >
            <button
                v-for="v in vendors"
                :key="v.slug"
                type="button"
                :disabled="!freeModels(v).length"
                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left hover:bg-fg/5 disabled:cursor-default disabled:opacity-35 disabled:hover:bg-transparent"
                @click="pickVendor(v)"
            >
                <VendorIcon :slug="v.slug" />
                <span class="flex-1 text-[15px]">{{ v.product }}</span>
                <span class="text-xs text-fg/40">{{ v.models.length }}</span>
                <Check v-if="v.slug === vendor?.slug" class="size-4 text-cool-soft" :stroke-width="1.5" />
            </button>
        </div>

        <!-- Модели выбранного вендора -->
        <div
            v-if="open === 'model'"
            class="scrollbar-glass absolute inset-x-0 top-full z-20 mt-2 max-h-[60vh] overflow-y-auto rounded-2xl border border-fg/10 bg-(--popover-bg) p-1 shadow-2xl backdrop-blur-xl"
        >
            <button
                v-for="m in vendor.models"
                :key="m.id"
                type="button"
                :disabled="disabledIds.includes(m.id)"
                class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left hover:bg-fg/5 disabled:cursor-default disabled:opacity-35 disabled:hover:bg-transparent"
                @click="pickModel(m.id)"
            >
                <span class="min-w-0 flex-1">
                    <span class="block text-[15px]">{{ m.name }}</span>
                    <span class="block text-xs text-fg/50">
                        <template v-if="m.price_input !== null && m.price_output !== null">
                            {{ formatPrice(m.price_input, m.price_currency) }} / {{ formatPrice(m.price_output, m.price_currency) }} за 1M токенов
                        </template>
                        <template v-else>цена не указана</template>
                    </span>
                </span>
                <Check v-if="m.id === modelId" class="size-4 text-cool-soft" :stroke-width="1.5" />
            </button>
        </div>
    </div>
</template>
