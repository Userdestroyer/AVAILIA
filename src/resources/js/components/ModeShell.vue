<script setup>
import { Menu } from 'lucide-vue-next';
import ModeSwitcher from '@/components/ModeSwitcher.vue';
import { useSidebar } from '@/composables/useSidebar';

defineProps({
    // Почти на весь экран (админка): без узкой колонки и места под правую панель
    wide: { type: Boolean, default: false },
});

const sidebarOpen = useSidebar();
</script>

<template>
    <!-- Общая геометрия страниц режимов: колонка по центру экрана + место под правую панель.
         Пустое место панели держим и там, где её нет, — иначе колонка и переключатель
         прыгали бы вбок при смене режима -->
    <div class="flex min-h-0 flex-1 gap-6">
        <!-- Правая панель шире левой, поэтому центр между ними левее центра экрана — компенсируем
             отступом, но только из лишнего места, не сужая колонку (cqw — ширина области колонки) -->
        <div class="@container flex min-w-0 flex-1 justify-center">
            <div
                class="flex min-h-0 w-full flex-1 flex-col gap-2 md:gap-3"
                :class="wide ? '' : 'max-w-[55rem] xl:ml-[min(6rem,max(0px,100cqw_-_55rem))]'"
            >
                <!-- Верхняя строка: ☰ (мобильный) · режимы · действия страницы — ничего не налезает -->
                <div class="flex items-center gap-2">
                    <button
                        v-frost
                        type="button"
                        class="glass shrink-0 rounded-full p-2 md:hidden"
                        title="Чаты"
                        @click="sidebarOpen = true"
                    >
                        <Menu class="size-5" :stroke-width="1.5" />
                    </button>
                    <ModeSwitcher class="min-w-0 flex-1" />
                    <slot name="actions" />
                </div>
                <slot />
            </div>
        </div>

        <slot v-if="!wide" name="panel">
            <div class="hidden w-[24rem] shrink-0 xl:block" aria-hidden="true" />
        </slot>
    </div>
</template>
