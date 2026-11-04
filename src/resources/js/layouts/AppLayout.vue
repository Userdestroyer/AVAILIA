<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { CircleHelp, History, LogOut, MessageSquare, Settings, Sparkle, SquarePen, ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CausticBackground from '@/components/CausticBackground.vue';
import SettingsModal from '@/components/SettingsModal.vue';
import { useThinking } from '@/composables/useThinking';

const thinking = useThinking();
const page = usePage();
const user = computed(() => page.props.auth.user);
const recentChats = computed(() => page.props.recentChats ?? []);
const historyOpen = ref(true);
const settingsOpen = ref(false);
const isActive = (id) => page.url === `/text/${id}`;
</script>

<template>
    <CausticBackground :thinking="thinking" />
    <SettingsModal v-model:open="settingsOpen" />

    <div class="flex h-dvh gap-6 p-4 md:p-6">
        <aside class="glass hidden w-72 shrink-0 flex-col rounded-3xl p-3 md:flex">
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
                <li v-for="chat in recentChats" :key="chat.id">
                    <Link
                        :href="`/text/${chat.id}`"
                        class="block truncate rounded-xl px-4 py-2 text-sm text-white/65 hover:bg-white/5 hover:text-white"
                        :class="{ 'bg-white/8 text-white': isActive(chat.id) }"
                    >
                        {{ chat.title || 'Без названия' }}
                    </Link>
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
