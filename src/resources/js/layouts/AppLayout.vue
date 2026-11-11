<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { CircleHelp, Disc3, History, LogOut, MessageSquare, Settings, Sparkle, SquarePen, ChevronRight, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { useSidebar } from '@/composables/useSidebar';
import { deleteJson } from '@/lib/http';
import AppBackground from '@/components/AppBackground.vue';
import SettingsModal from '@/components/SettingsModal.vue';
import { useThinking } from '@/composables/useThinking';

const thinking = useThinking();

// На мобильном боковая панель — шторка слева; открывает ☰ в верхней строке страницы
const isDesktop = useMediaQuery('(min-width: 768px)');
const sidebarOpen = useSidebar();
// Перешли в чат или на другую страницу — шторку убираем
const stopNavigateListener = router.on('navigate', () => (sidebarOpen.value = false));
onBeforeUnmount(stopNavigateListener);
const page = usePage();
const user = computed(() => page.props.auth.user);
// Удалённые прячем сразу, не дожидаясь сервера; при ошибке возвращаем
const hiddenChats = ref(new Set());
const recentChats = computed(() => (page.props.recentChats ?? []).filter((chat) => !hiddenChats.value.has(chat.id)));
const historyOpen = ref(true);
const settingsOpen = ref(false);

// Время у чата в истории: сегодня — часы, дальше «Вчера», «Позавчера», потом дата
const DAY = 86_400_000;
const when = (iso) => {
    if (!iso) return '';
    const date = new Date(iso);
    const start = new Date();
    start.setHours(0, 0, 0, 0);
    const days = Math.ceil((start - date) / DAY);
    if (date >= start) return date.toLocaleTimeString('ru', { hour: '2-digit', minute: '2-digit' });
    if (days <= 1) return 'Вчера';
    if (days === 2) return 'Позавчера';
    return date.toLocaleDateString('ru', { day: 'numeric', month: 'short' }).replace('.', '');
};

// Связь с сервером: браузер знает, что сеть пропала, раньше любого запроса
const online = ref(navigator.onLine);
const setOnline = () => (online.value = navigator.onLine);
window.addEventListener('online', setOnline);
window.addEventListener('offline', setOnline);
onBeforeUnmount(() => {
    window.removeEventListener('online', setOnline);
    window.removeEventListener('offline', setOnline);
});
const settingsTab = ref('context');
const openSettings = (tab) => {
    settingsTab.value = tab;
    settingsOpen.value = true;
};
const isActive = (id) => page.url === `/text/${id}` || window.location.pathname === `/text/${id}`;

// Урна: первый клик — «Удалить?», второй (в течение 3 с) — удаляет
const confirming = ref(null);
let confirmTimer = 0;

const askDelete = (id) => {
    confirming.value = id;
    clearTimeout(confirmTimer);
    confirmTimer = setTimeout(() => (confirming.value = null), 3000);
};

const deleteChat = async (id) => {
    const wasOpen = isActive(id);
    confirming.value = null;
    hiddenChats.value = new Set(hiddenChats.value).add(id);
    if (wasOpen) router.visit('/text');

    try {
        await deleteJson(`/text/${id}`);
    } catch {
        const restored = new Set(hiddenChats.value);
        restored.delete(id);
        hiddenChats.value = restored;
    }
};
</script>

<template>
    <AppBackground :thinking="thinking" />
    <SettingsModal v-model:open="settingsOpen" v-model:tab="settingsTab" />

    <!-- Под открытой шторкой всё размывается; тап мимо — закрыть -->
    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-200" leave-to-class="opacity-0">
        <div v-if="!isDesktop && sidebarOpen" class="fixed inset-0 z-40 bg-black/30 backdrop-blur-md" @click="sidebarOpen = false" />
    </Transition>

    <div data-app-shell class="flex h-dvh gap-6 overflow-hidden p-2 md:p-6">
        <!-- На месте (md+) — матовость из шейдера; шторкой поверх контента — настоящее размытие -->
        <aside
            v-frost="isDesktop"
            class="glass flex w-72 shrink-0 flex-col rounded-3xl p-3"
            :class="isDesktop ? '' : ['fixed inset-y-3 left-3 z-50 max-w-[calc(100vw-1.5rem)] transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]', sidebarOpen ? 'translate-x-0' : '-translate-x-[110%]']"
            :inert="!isDesktop && !sidebarOpen"
        >
            <div class="flex items-center justify-between px-2 py-2">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-cool to-[#7c5cff] shadow-lg shadow-cool/30">
                        <Sparkle class="size-4 fill-white text-white" />
                    </span>
                    <span class="brand text-[15px] font-normal md:text-base">Availia</span>
                </div>
                <Link href="/text" class="glass-inset rounded-xl p-2 text-fg/70 hover:text-fg" title="Новый чат">
                    <SquarePen class="vein size-4" :stroke-width="1.5" />
                </Link>
            </div>

            <nav class="mt-4 space-y-1">
                <Link href="/text" class="glass-accent flex items-center gap-3 rounded-2xl px-4 py-3 text-[15px]">
                    <MessageSquare class="size-[18px] text-cool-soft" :stroke-width="1.5" />
                    Новый чат
                </Link>
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-[15px] text-fg/80 hover:bg-fg/5"
                    @click="historyOpen = !historyOpen"
                >
                    <History class="vein size-[18px]" :stroke-width="1.5" />
                    История чатов
                    <ChevronRight class="vein ml-auto size-4 transition" :class="{ 'rotate-90': historyOpen }" :stroke-width="1.5" />
                </button>
            </nav>

            <ul v-if="historyOpen" class="scrollbar-glass -mr-1 min-h-0 flex-1 space-y-0.5 overflow-y-auto pr-1 pl-2">
                <li v-for="chat in recentChats" :key="chat.id" class="group relative">
                    <!-- Подгружается при наведении — к клику чат уже на месте -->
                    <Link
                        :href="`/text/${chat.id}`"
                        prefetch="hover"
                        cache-for="5s"
                        class="flex items-center gap-2 rounded-xl py-2 pr-2 pl-4 text-sm text-fg/65 hover:bg-fg/5 hover:text-fg"
                        :class="{ 'bg-fg/8 text-fg': isActive(chat.id) }"
                    >
                        <span class="min-w-0 flex-1 truncate">{{ chat.title || 'Без названия' }}</span>
                        <!-- Время уступает место урне при наведении -->
                        <span class="shrink-0 text-[11px] text-fg/40 tabular-nums transition group-hover:opacity-0">{{ when(chat.updated_at) }}</span>
                    </Link>
                    <button
                        v-if="confirming === chat.id"
                        type="button"
                        class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded-lg bg-hot/20 px-2 py-1 text-xs text-hot-soft hover:bg-hot/30"
                        @click="deleteChat(chat.id)"
                    >
                        Удалить?
                    </button>
                    <button
                        v-else
                        type="button"
                        class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded-lg p-1.5 text-fg/40 opacity-0 transition group-hover:opacity-100 hover:bg-fg/5 hover:text-hot-soft focus-visible:opacity-100"
                        title="Удалить чат"
                        @click="askDelete(chat.id)"
                    >
                        <Trash2 class="size-3.5" :stroke-width="1.5" />
                    </button>
                </li>
                <li v-if="!recentChats.length" class="px-4 py-2 text-sm text-fg/40">Пока пусто</li>
            </ul>

            <div class="mt-auto space-y-1 pt-2">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-2.5 text-[15px] text-fg/80 hover:bg-fg/5"
                    @click="openSettings('appearance')"
                >
                    <Settings class="vein size-[18px]" :stroke-width="1.5" />
                    Настройки
                </button>
                <button type="button" class="flex w-full items-center gap-3 rounded-2xl px-4 py-2.5 text-[15px] text-fg/80 hover:bg-fg/5">
                    <CircleHelp class="vein size-[18px]" :stroke-width="1.5" />
                    Помощь
                </button>

                <div class="mt-2 flex items-center gap-1 border-t border-fg/10 pt-2">
                    <!-- Профиль: «Обо мне» — картриджи контекста -->
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-3 rounded-2xl px-4 py-1.5 text-left hover:bg-fg/5"
                        title="Обо мне — картриджи контекста"
                        @click="openSettings('context')"
                    >
                        <Disc3 class="vein size-[18px] shrink-0" :stroke-width="1.5" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm">{{ user.username }}</span>
                            <span class="block text-xs text-fg/50">{{ user.plan.name }} · Обо мне</span>
                        </span>
                    </button>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="ml-auto rounded-lg p-1.5 text-fg/50 hover:bg-fg/5 hover:text-fg"
                        title="Выйти"
                    >
                        <LogOut class="vein size-4" :stroke-width="1.5" />
                    </Link>
                </div>
            </div>
        </aside>

        <main class="relative flex min-w-0 flex-1 flex-col">
            <slot />
        </main>
    </div>

    <!-- Строка статуса — в нижнем поле экрана, на десктопе -->
    <footer class="pointer-events-none fixed inset-x-0 bottom-0 hidden h-6 items-center justify-between px-8 md:flex" aria-live="polite">
        <span class="flex items-center gap-2 text-[11px] text-on-sky/65">
            <span class="size-1.5 rounded-full" :class="online ? 'bg-emerald-400 shadow-[0_0_8px_rgb(52_211_153/0.9)]' : 'bg-hot shadow-[0_0_8px_var(--color-hot)]'" />
            {{ online ? 'Система онлайн' : 'Нет связи' }}
        </span>
        <span class="brand flex items-center gap-3 text-[10px] text-on-sky/55">
            <span class="h-px w-12 bg-on-sky/40" />
            Больше моделей. Ближе к вам.
        </span>
    </footer>
</template>
