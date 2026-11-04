<script setup>
import ModeSwitcher from '@/components/ModeSwitcher.vue';
</script>

<template>
    <!-- Общая геометрия страниц режимов: колонка по центру экрана + место под правую панель.
         Пустое место панели держим и там, где её нет, — иначе колонка и переключатель
         прыгали бы вбок при смене режима -->
    <div class="flex min-h-0 flex-1 gap-6">
        <!-- Правая панель шире левой, поэтому центр между ними левее центра экрана — компенсируем
             отступом, но только из лишнего места, не сужая колонку (cqw — ширина области колонки) -->
        <div class="@container flex min-w-0 flex-1 justify-center">
            <div class="flex min-h-0 w-full max-w-3xl flex-1 flex-col gap-3 xl:ml-[min(6rem,max(0px,100cqw_-_48rem))]">
                <ModeSwitcher />
                <slot />
            </div>
        </div>

        <slot name="panel">
            <div class="hidden w-[24rem] shrink-0 xl:block" aria-hidden="true" />
        </slot>
    </div>
</template>
