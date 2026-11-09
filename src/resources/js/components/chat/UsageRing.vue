<script setup>
import { Sparkles, Zap } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { formatEnergy } from '@/lib/format';

/**
 * Энергия у кнопки отправки: кольцо заполняется по большему из окон (сессия, неделя),
 * по клику — попап с обоими окнами, временем сброса и балансом токенов. Рубли — при наведении.
 */
const props = defineProps({
    usage: { type: Object, default: null },
    rate: { type: Number, default: 10 },
});

const open = ref(false);
const now = ref(Date.now());

const WINDOWS = [
    { kind: 'session', label: 'Сессия' },
    { kind: 'week', label: 'Неделя' },
];

const text = computed(() => props.usage?.windows?.text ?? {});
const percent = (window) => (window?.limit ? Math.min(100, (window.spent / window.limit) * 100) : 0);
const fill = computed(() => Math.max(...WINDOWS.map(({ kind }) => percent(text.value[kind]))));
const tone = (value) => (value >= 100 ? 'var(--color-hot)' : value >= 80 ? '#f5b14c' : 'var(--color-cool)');

const energy = (rub) => formatEnergy(rub * props.rate);
const rub = (value) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 2 }).format(value);

// «через 3 ч 12 мин» / «в пн 14:00»; окна ещё нет — начнётся с первого запроса
const resets = (window) => {
    if (!window?.resets_at) return `начнётся с первого запроса · ${window?.hours >= 24 ? `${window.hours / 24} дн` : `${window?.hours} ч`}`;
    const at = new Date(window.resets_at);
    const minutes = Math.max(0, Math.round((at - now.value) / 60000));
    if (minutes < 60) return `сброс через ${minutes} мин`;
    if (minutes < 24 * 60) return `сброс через ${Math.floor(minutes / 60)} ч ${minutes % 60} мин`;
    return `сброс ${at.toLocaleString('ru', { weekday: 'short', hour: '2-digit', minute: '2-digit' })}`;
};

// Пока попап открыт, «сброс через…» идёт вживую
let timer = 0;
watch(open, (value) => {
    clearInterval(timer);
    now.value = Date.now();
    if (value) timer = setInterval(() => (now.value = Date.now()), 30000);
});
onBeforeUnmount(() => clearInterval(timer));

const RADIUS = 8;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative flex size-9 items-center justify-center rounded-full transition hover:bg-fg/5 md:size-10"
            :title="`Энергия: ${Math.round(fill)}% окна израсходовано`"
            @click="open = !open"
        >
            <svg viewBox="0 0 20 20" class="absolute size-7 -rotate-90 md:size-8" aria-hidden="true">
                <circle cx="10" cy="10" :r="RADIUS" fill="none" stroke="currentColor" stroke-width="1.6" class="text-fg/15" />
                <circle
                    cx="10"
                    cy="10"
                    :r="RADIUS"
                    fill="none"
                    :stroke="tone(fill)"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    :stroke-dasharray="CIRCUMFERENCE"
                    :stroke-dashoffset="CIRCUMFERENCE * (1 - fill / 100)"
                    class="transition-[stroke-dashoffset] duration-700 ease-out"
                    :style="{ filter: `drop-shadow(0 0 3px ${tone(fill)})` }"
                />
            </svg>
            <Zap class="size-3.5 fill-current" :style="{ color: tone(fill) }" :stroke-width="0" />
        </button>

        <div v-if="open" class="fixed inset-0 z-10" @click="open = false" />
        <div
            v-if="open"
            class="absolute right-0 bottom-full z-20 mb-3 w-72 space-y-3 rounded-2xl border border-fg/10 bg-(--popover-bg) p-4 shadow-2xl backdrop-blur-xl"
        >
            <div class="brand flex items-center gap-1.5 text-[11px] text-(--accent-text)">
                <Zap class="size-3.5 fill-current" :stroke-width="0" /> Энергия
            </div>

            <div v-for="{ kind, label } in WINDOWS" :key="kind" class="space-y-1.5">
                <div class="flex items-baseline justify-between text-sm">
                    <span>{{ label }}</span>
                    <span
                        class="text-xs text-fg/60 tabular-nums"
                        :title="text[kind]?.limit ? `${rub(text[kind].spent)} из ${rub(text[kind].limit)}` : `${rub(text[kind]?.spent ?? 0)}`"
                    >
                        <template v-if="text[kind]?.limit">⚡ {{ energy(text[kind].spent) }} из {{ energy(text[kind].limit) }}</template>
                        <template v-else>без ограничений</template>
                    </span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-fg/10">
                    <div
                        class="h-full rounded-full transition-[width] duration-700 ease-out"
                        :style="{ width: `${percent(text[kind])}%`, background: tone(percent(text[kind])), boxShadow: `0 0 8px ${tone(percent(text[kind]))}` }"
                    />
                </div>
                <div class="text-[11px] text-fg/45">{{ resets(text[kind]) }}</div>
            </div>

            <div class="flex items-center justify-between border-t border-fg/10 pt-3 text-sm">
                <span class="flex items-center gap-1.5"><Sparkles class="size-3.5 text-cool-soft" :stroke-width="1.5" /> Токены контента</span>
                <span class="tabular-nums">{{ usage?.tokens ?? 0 }}</span>
            </div>
        </div>
    </div>
</template>
