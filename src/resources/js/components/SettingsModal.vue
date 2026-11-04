<script setup>
import { Moon, Sun, X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useTheme } from '@/composables/useTheme';
import { THEMES } from '@/lib/theme';

const open = defineModel('open', { type: Boolean, default: false });

const { theme, setTheme } = useTheme();
const error = ref(null);

const ICONS = { light: Sun, dark: Moon };

const chooseTheme = async (value) => {
    error.value = null;
    try {
        await setTheme(value);
    } catch {
        error.value = 'Не удалось сохранить тему — вернули прежнюю';
    }
};

const onKeydown = (event) => {
    if (event.key === 'Escape') open.value = false;
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/25 p-4 backdrop-blur-sm" @click.self="open = false">
                <section class="glass w-full max-w-lg rounded-3xl p-6" role="dialog" aria-modal="true" aria-labelledby="settings-title">
                    <header class="flex items-center justify-between">
                        <h2 id="settings-title" class="text-xl font-light">Настройки</h2>
                        <button type="button" class="rounded-xl p-2 text-white/60 hover:bg-white/5 hover:text-white" title="Закрыть" @click="open = false">
                            <X class="size-5" :stroke-width="1.5" />
                        </button>
                    </header>

                    <div class="mt-6 space-y-3">
                        <h3 class="text-sm text-white/50">Оформление</h3>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-[15px]">Тема</span>
                            <div class="glass-inset flex rounded-2xl p-1">
                                <button
                                    v-for="(meta, value) in THEMES"
                                    :key="value"
                                    type="button"
                                    class="flex items-center gap-2 rounded-xl px-4 py-2 text-sm transition"
                                    :class="theme === value ? 'bg-white/15 text-white shadow-sm' : 'text-white/60 hover:text-white'"
                                    :aria-pressed="theme === value"
                                    @click="chooseTheme(value)"
                                >
                                    <component :is="ICONS[value]" class="size-4" :stroke-width="1.5" />
                                    {{ meta.label }}
                                </button>
                            </div>
                        </div>

                        <p v-if="error" class="text-sm text-hot-soft">{{ error }}</p>
                    </div>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
