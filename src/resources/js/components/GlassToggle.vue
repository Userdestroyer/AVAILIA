<script setup>
defineProps({
    icon: { type: [Object, Function], default: null },
    label: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});

const model = defineModel({ type: Boolean, default: false });
</script>

<template>
    <!-- relative: скрытый чекбокс (sr-only — absolute) стоит внутри переключателя, а не у дальнего предка;
         иначе фокус по клику прокручивал страницу к тому предку -->
    <label class="relative flex items-center gap-3 text-[15px]" :class="disabled ? 'cursor-default opacity-40' : 'cursor-pointer'">
        <component :is="icon" v-if="icon" class="size-5 text-fg/70" :stroke-width="1.5" />
        {{ label }}
        <input v-model="model" type="checkbox" class="peer sr-only" :disabled="disabled" />
        <span
            class="relative ml-auto h-6 w-11 rounded-full bg-fg/15 transition peer-checked:bg-cool peer-checked:shadow-(--toggle-glow) peer-focus-visible:ring-2 peer-focus-visible:ring-cool-soft after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-5"
        />
    </label>
</template>
