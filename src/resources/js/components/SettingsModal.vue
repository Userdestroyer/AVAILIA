<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Disc3, Moon, Palette, Pin, Plus, Sun, Trash2, UserRound, X } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useContextPlates } from '@/composables/useContextPlates';
import { useTheme } from '@/composables/useTheme';
import { FONTS, STYLES, THEMES } from '@/lib/theme';

/**
 * Профиль и настройки: «Обо мне» (картриджи контекста) и оформление — в одном окне.
 * Открывается из «Настроек» (оформление) и по имени пользователя («Обо мне»).
 */
const open = defineModel('open', { type: Boolean, default: false });
const tab = defineModel('tab', { type: String, default: 'context' });

const TABS = [
    { key: 'context', label: 'Обо мне', icon: UserRound },
    { key: 'appearance', label: 'Оформление', icon: Palette },
];

// ── Оформление ─────────────────────────────────────────
const { theme, style, font, setTheme, setStyle, setFont } = useTheme();
const themeError = ref(null);
const ICONS = { light: Sun, dark: Moon };

const choose = async (setter, value) => {
    themeError.value = null;
    try {
        await setter(value);
    } catch {
        themeError.value = 'Не удалось сохранить оформление — вернули прежнее';
    }
};

// Шрифты акцентов прогреваем при открытии вкладки — превью и переключение без задержки
watch([open, tab], ([isOpen, current]) => {
    if (isOpen && current === 'appearance') {
        Object.values(FONTS).forEach(({ family }) => document.fonts?.load(`400 1em "${family}"`).catch(() => {}));
    }
});

// ── Обо мне ────────────────────────────────────────────
const { plates, knownTags, loaded, load, isDirty, dirtyCount, create, save, revert, remove } = useContextPlates();
const expanded = ref(null);
const page = usePage();

const STARTERS = [
    { title: 'Общее', tags: ['общее'], hint: 'Имя, возраст, город, язык общения' },
    { title: 'Работа', tags: ['работа'], hint: 'Чем занимаюсь, стек, задачи' },
    { title: 'Хобби', tags: ['хобби'], hint: 'Увлечения и интересы' },
    { title: 'Здоровье', tags: ['здоровье'], hint: 'Важное о здоровье — по желанию' },
];

// Картриджи нужны следующим кликом — грузим, пока браузер простаивает, а не при открытии
onMounted(() => (window.requestIdleCallback ?? ((fn) => setTimeout(fn, 300)))(() => load()));
watch(open, (value) => value && load());

const add = (starter) => {
    const plate = create(starter);
    expanded.value = plate.uid;
    nextTick(() => requestAnimationFrame(() => {
        const input = document.getElementById(`plate-title-${plate.uid}`);
        input?.focus();
        input?.select();
    }));
};

const onSave = async (plate) => {
    if (await save(plate)) refreshChat();
};

// Чат держит список картриджей в пропсах (для # и пластинок) — обновляем его и сбрасываем предзагрузку
const refreshChat = () => {
    router.flushAll();
    if (page.props.plates !== undefined) router.reload({ only: ['plates'] });
};

const addTag = (plate, event) => {
    const value = event.target.value.replace(/^#/, '').trim();
    event.target.value = '';
    if (value && !plate.draft.tags.some((t) => t.toLowerCase() === value.toLowerCase())) plate.draft.tags.push(value);
};

// Удаление — вторым кликом, как у чатов
const confirming = ref(null);
let confirmTimer = 0;
const onRemove = async (plate) => {
    if (confirming.value !== plate.uid) {
        confirming.value = plate.uid;
        clearTimeout(confirmTimer);
        confirmTimer = setTimeout(() => (confirming.value = null), 3000);
        return;
    }
    confirming.value = null;
    if (await remove(plate)) refreshChat();
};

// Поле растёт по тексту; считаем на следующем кадре — при монтировании высота ещё 0
const autosize = (el) => {
    if (!el) return;
    requestAnimationFrame(() => {
        el.style.height = 'auto';
        if (el.scrollHeight) el.style.height = `${el.scrollHeight}px`;
    });
};

const onKeydown = (event) => {
    if (!open.value) return;
    if (event.key === 'Escape') open.value = false;
    // ⌘/Ctrl+S — сохранить картриджи с изменениями
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's' && tab.value === 'context') {
        event.preventDefault();
        plates.value.filter(isDirty).forEach(onSave);
    }
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
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/25 p-2 backdrop-blur-sm md:p-4" @click.self="open = false">
                <section
                    class="glass flex max-h-[calc(100dvh-1rem)] w-full max-w-2xl flex-col rounded-3xl md:max-h-[85dvh]"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Профиль и настройки"
                >
                    <header class="flex items-center justify-between gap-3 p-3 md:p-4">
                        <div class="glass-inset flex rounded-2xl p-1">
                            <button
                                v-for="item in TABS"
                                :key="item.key"
                                type="button"
                                class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm transition md:px-4"
                                :class="tab === item.key ? 'pill-active' : 'text-fg/60 hover:text-fg'"
                                @click="tab = item.key"
                            >
                                <component :is="item.icon" class="size-4" :stroke-width="1.5" />
                                {{ item.label }}
                                <span v-if="item.key === 'context' && dirtyCount" class="size-1.5 rounded-full bg-hot" title="Есть несохранённые изменения" />
                            </button>
                        </div>
                        <button type="button" class="vein rounded-xl p-2 hover:text-fg" title="Закрыть" @click="open = false">
                            <X class="size-5" :stroke-width="1.5" />
                        </button>
                    </header>

                    <!-- Обо мне -->
                    <div v-if="tab === 'context'" class="scrollbar-glass min-h-0 flex-1 overflow-y-auto px-3 pb-3 md:px-5 md:pb-5">
                        <p class="px-1 text-sm text-fg/55">
                            Картриджи контекста. В запрос они вставляются целиком, копировать ничего не нужно: подключаются через
                            <span class="text-fg/80">#тег</span> в поле ввода или сами, если закреплены. Модель видит только подключённые.
                        </p>

                        <p v-if="!loaded" class="mt-4 px-1 text-sm text-fg/40">Загружаю…</p>

                        <!-- Быстрый старт -->
                        <div v-else-if="!plates.length" class="mt-4 grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="starter in STARTERS"
                                :key="starter.title"
                                type="button"
                                class="glass-inset flex items-start gap-3 rounded-2xl p-3.5 text-left transition hover:bg-fg/8"
                                @click="add(starter)"
                            >
                                <Disc3 class="vein mt-0.5 size-5 shrink-0" :stroke-width="1.5" />
                                <span>
                                    <span class="block text-[15px]">{{ starter.title }}</span>
                                    <span class="block text-xs text-fg/50">{{ starter.hint }}</span>
                                </span>
                            </button>
                        </div>

                        <ul class="mt-4 space-y-2">
                            <li v-for="plate in plates" :key="plate.uid" class="glass-inset rounded-2xl" :class="{ 'ring-1 ring-hot/40': isDirty(plate) }">
                                <!-- Шапка: диск светится, если картридж закреплён -->
                                <div class="flex items-center gap-2 px-3 pt-2.5 pb-1">
                                    <Disc3 class="size-5 shrink-0 transition" :class="plate.draft.is_pinned ? 'text-cool-soft' : 'vein'" :stroke-width="1.5" />
                                    <input
                                        :id="`plate-title-${plate.uid}`"
                                        v-model="plate.draft.title"
                                        maxlength="80"
                                        class="min-w-0 flex-1 bg-transparent text-[15px] outline-none"
                                        @focus="expanded = plate.uid"
                                    />
                                    <span v-if="plate.saved?.tokens !== undefined" class="hidden shrink-0 text-xs text-fg/40 tabular-nums sm:inline" title="Примерно столько токенов добавит к каждому запросу">
                                        ≈ {{ plate.saved.tokens }} ток.
                                    </span>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-lg p-1 transition"
                                        :class="plate.draft.is_pinned ? 'text-cool-soft' : 'vein'"
                                        :title="plate.draft.is_pinned ? 'Закреплён: подключается к каждому новому чату' : 'Закрепить: подключать к каждому новому чату'"
                                        @click="plate.draft.is_pinned = !plate.draft.is_pinned"
                                    >
                                        <Pin class="size-4" :stroke-width="1.5" />
                                    </button>
                                    <button type="button" class="vein shrink-0 rounded-lg p-1" :title="expanded === plate.uid ? 'Свернуть' : 'Развернуть'" @click="expanded = expanded === plate.uid ? null : plate.uid">
                                        <ChevronDown class="size-4 transition" :class="{ 'rotate-180': expanded === plate.uid }" :stroke-width="1.5" />
                                    </button>
                                </div>

                                <!-- Теги -->
                                <div class="flex flex-wrap items-center gap-1.5 px-3 pb-2.5 pl-10">
                                    <span v-for="tag in plate.draft.tags" :key="tag" class="flex items-center gap-1 rounded-full bg-fg/8 py-0.5 pr-1 pl-2 text-xs text-fg/75">
                                        #{{ tag }}
                                        <button type="button" class="rounded-full p-0.5 text-fg/40 hover:text-fg" title="Убрать тег" @click="plate.draft.tags = plate.draft.tags.filter((t) => t !== tag)">
                                            <X class="size-3" :stroke-width="2" />
                                        </button>
                                    </span>
                                    <input
                                        :list="`tags-${plate.uid}`"
                                        placeholder="+ тег"
                                        maxlength="40"
                                        class="w-20 bg-transparent text-xs outline-none placeholder:text-fg/35 focus:w-32"
                                        @keydown="['Enter', ','].includes($event.key) && ($event.preventDefault(), addTag(plate, $event))"
                                        @blur="addTag(plate, $event)"
                                    />
                                    <datalist :id="`tags-${plate.uid}`">
                                        <option v-for="tag in knownTags" :key="tag" :value="tag" />
                                    </datalist>
                                </div>

                                <!-- Содержимое -->
                                <div v-if="expanded === plate.uid" class="border-t border-fg/6 px-3 pt-2.5">
                                    <textarea
                                        :ref="autosize"
                                        v-model="plate.draft.content"
                                        rows="4"
                                        placeholder="Что модели стоит знать: факты, предпочтения, контекст. Пишите как для умного коллеги."
                                        class="min-h-24 w-full resize-none bg-transparent text-[0.9rem] leading-relaxed outline-none placeholder:text-fg/35 md:text-[15px]"
                                        @input="autosize($event.target)"
                                    />
                                </div>

                                <!-- Действия: сохранить явно -->
                                <div v-if="expanded === plate.uid || isDirty(plate)" class="flex flex-wrap items-center gap-2 px-3 pt-1 pb-2.5 text-xs">
                                    <span v-if="plate.status" class="text-hot-soft">{{ plate.status }}</span>
                                    <span v-else-if="plate.saved" class="text-fg/40">Версий в истории: {{ plate.saved.versions ?? 1 }}</span>
                                    <button
                                        type="button"
                                        class="ml-auto flex items-center gap-1 rounded-lg px-2 py-1 transition"
                                        :class="confirming === plate.uid ? 'bg-hot/20 text-hot-soft' : 'vein hover:text-hot-soft'"
                                        @click="onRemove(plate)"
                                    >
                                        <Trash2 class="size-3.5" :stroke-width="1.5" />
                                        {{ confirming === plate.uid ? 'Удалить?' : 'Удалить' }}
                                    </button>
                                    <template v-if="isDirty(plate)">
                                        <button type="button" class="rounded-lg px-2.5 py-1 text-fg/60 transition hover:bg-fg/5 hover:text-fg" @click="revert(plate)">
                                            Отменить
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="!plate.draft.title.trim()"
                                            class="rounded-lg bg-cool px-3 py-1 text-white shadow-lg shadow-cool/30 transition hover:bg-(--accent-hi) disabled:opacity-40"
                                            title="Сохранить (⌘/Ctrl+S)"
                                            @click="onSave(plate)"
                                        >
                                            Сохранить
                                        </button>
                                    </template>
                                </div>
                            </li>
                        </ul>

                        <button
                            v-if="loaded && plates.length"
                            type="button"
                            class="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-fg/15 py-2.5 text-sm text-fg/60 transition hover:border-fg/30 hover:text-fg"
                            @click="add()"
                        >
                            <Plus class="size-4" :stroke-width="1.5" />
                            Картридж
                        </button>
                    </div>

                    <!-- Оформление -->
                    <div v-else class="scrollbar-glass min-h-0 flex-1 space-y-5 overflow-y-auto px-4 pb-5 md:px-5">
                        <!-- Стиль: смена гасит один фон и поднимает другой, вдвоём они не живут -->
                        <div class="space-y-2">
                            <span class="text-[15px]">Стиль</span>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <button
                                    v-for="(meta, value) in STYLES"
                                    :key="value"
                                    type="button"
                                    class="glass-inset rounded-2xl p-3.5 text-left transition"
                                    :class="style === value ? 'ring-1 ring-cool/80 shadow-[0_0_18px_rgb(91_140_255/0.35)]' : 'hover:bg-fg/5'"
                                    :aria-pressed="style === value"
                                    @click="choose(setStyle, value)"
                                >
                                    <span class="block text-[15px]" :class="{ 'brand text-sm': value === 'jiva' }">{{ meta.label }}</span>
                                    <span class="mt-0.5 block text-xs text-fg/55">{{ meta.hint }}</span>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-[15px]">Схема</span>
                            <div class="glass-inset flex rounded-2xl p-1">
                                <button
                                    v-for="(meta, value) in THEMES"
                                    :key="value"
                                    type="button"
                                    class="flex items-center gap-2 rounded-xl px-4 py-2 text-sm transition"
                                    :class="theme === value ? 'pill-active' : 'text-fg/60 hover:text-fg'"
                                    :aria-pressed="theme === value"
                                    @click="choose(setTheme, value)"
                                >
                                    <component :is="ICONS[value]" class="size-4" :stroke-width="1.5" />
                                    {{ meta.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Шрифт акцентов (только JIVA): превью — логотипом этим шрифтом -->
                        <div v-if="style === 'jiva'" class="space-y-2">
                            <span class="text-[15px]">Шрифт акцентов</span>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <button
                                    v-for="(meta, value) in FONTS"
                                    :key="value"
                                    type="button"
                                    class="glass-inset rounded-2xl px-3 py-3 text-center transition"
                                    :class="font === value ? 'ring-1 ring-cool/80 shadow-[0_0_18px_rgb(91_140_255/0.35)]' : 'hover:bg-fg/5'"
                                    :aria-pressed="font === value"
                                    @click="choose(setFont, value)"
                                >
                                    <span class="block text-base tracking-[0.3em] uppercase" :style="{ fontFamily: `'${meta.family}'` }">Availia</span>
                                    <span class="mt-1 block text-xs text-fg/55">{{ meta.label }}</span>
                                </button>
                            </div>
                        </div>

                        <p v-if="themeError" class="text-sm text-hot-soft">{{ themeError }}</p>
                    </div>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
