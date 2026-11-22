<script setup>
import { Paperclip } from 'lucide-vue-next';

defineProps({
    show: { type: Boolean, default: false },
    hint: { type: String, default: 'Картинки и PDF' },
});
</script>

<template>
    <!-- Файл над окном: отпустите — прикрепится -->
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="show" class="pointer-events-none fixed inset-0 z-[60] flex items-center justify-center bg-black/20 p-4 backdrop-blur-[2px]">
                <div class="glass rounded-3xl border-2 border-dashed px-8 py-6 text-center">
                    <Paperclip class="mx-auto mb-2 size-7 text-cool-soft" :stroke-width="1.5" />
                    <p class="text-sm md:text-base">Отпустите — файл прикрепится к вопросу</p>
                    <p class="mt-1 text-xs text-fg/50">{{ hint }}</p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
