<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { CircleHelp, History, LogOut, MessageSquare, Settings, Sparkle, SquarePen, ChevronRight, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useMediaQuery } from '@/composables/useMediaQuery';
import { useSidebar } from '@/composables/useSidebar';
import { deleteJson } from '@/lib/http';
import CausticBackground from '@/components/CausticBackground.vue';
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
    <CausticBackground :thinking="thinking" />
    <SettingsModal v-model:open="settingsOpen" />

    <!-- Под открытой шторкой всё размывается; тап мимо — закрыть -->
    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-200" leave-to-class="opacity-0">
        <div v-if="!isDesktop && sidebarOpen" class="fixed inset-0 z-40 bg-black/30 backdrop-blur-md" @click="sidebarOpen = false" />
    </Transition>

    <div class="flex h-dvh gap-6 p-2 md:p-6">
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
                    <span class="text-lg font-normal tracking-wide">AVAILIA</span>
                </div>
                <Link href="/text" class="glass-inset rounded-xl p-2 text-white/70 hover:text-white" title="Новый чат">
                    <SquarePen class="size-4" :stroke-width="1.5" />
                </Link>
            </div>

            <nav class="mt-4 space-y-1">
                <Link href="/text" class="glass-inset flex items-center gap-3 rounded-2xl px-4 py-3 text-[15px]">
                    <MessageSquare class="size-[18px] text-cool-soft" :stroke-width="1.5" />
                    Новый чат
                </Link>
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-[15px] text-white/80 hover:bg-white/5"
                    @click="historyOpen = !historyOpen"
                >
                    <History class="size-[18px]" :stroke-width="1.5" />
                    История чатов
                    <ChevronRight class="ml-auto size-4 text-white/40 transition" :class="{ 'rotate-90': historyOpen }" :stroke-width="1.5" />
                </button>
            </nav>

            <ul v-if="historyOpen" class="scrollbar-glass -mr-1 min-h-0 flex-1 space-y-0.5 overflow-y-auto pr-1 pl-2">
                <li v-for="chat in recentChats" :key="chat.id" class="group relative">
                    <Link
                        :href="`/text/${chat.id}`"
                        class="block truncate rounded-xl py-2 pr-10 pl-4 text-sm text-white/65 hover:bg-white/5 hover:text-white"
                        :class="{ 'bg-white/8 text-white': isActive(chat.id) }"
                    >
                        {{ chat.title || 'Без названия' }}
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
                        class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded-lg p-1.5 text-white/40 opacity-0 transition group-hover:opacity-100 hover:bg-white/5 hover:text-hot-soft focus-visible:opacity-100"
                        title="Удалить чат"
                        @click="askDelete(chat.id)"
                    >
                        <Trash2 class="size-3.5" :stroke-width="1.5" />
                    </button>
                </li>
                <li v-if="!recentChats.length" class="px-4 py-2 text-sm text-white/40">Пока пусто</li>
            </ul>

            <div class="mt-auto space-y-1 pt-2">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-2.5 text-[15px] text-white/80 hover:bg-white/5"
                    @click="settingsOpen = true"
                >
                    <Settings class="size-[18px]" :stroke-width="1.5" />
                    Настройки
                </button>
                <button type="button" class="flex w-full items-center gap-3 rounded-2xl px-4 py-2.5 text-[15px] text-white/80 hover:bg-white/5">
                    <CircleHelp class="size-[18px]" :stroke-width="1.5" />
                    Помощь
                </button>

                <div class="mt-2 flex items-center gap-3 border-t border-white/10 px-4 pt-3 pb-1">
                    <div class="min-w-0">
                        <div class="truncate text-sm">{{ user.username }}</div>
                        <div class="text-xs text-white/50">{{ user.plan.name }}</div>
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="ml-auto rounded-lg p-1.5 text-white/50 hover:bg-white/5 hover:text-white"
                        title="Выйти"
                    >
                        <LogOut class="size-4" :stroke-width="1.5" />
                    </Link>
                </div>
            </div>
        </aside>

        <main class="relative flex min-w-0 flex-1 flex-col">
            <slot />
        </main>
    </div>
</template>
