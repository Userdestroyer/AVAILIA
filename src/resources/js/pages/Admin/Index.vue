<script setup>
import { Head } from '@inertiajs/vue3';
import { Boxes, Check, KeyRound, Plus, RefreshCw, Server, X } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import GlassToggle from '@/components/GlassToggle.vue';
import ModeShell from '@/components/ModeShell.vue';
import VendorIcon from '@/components/VendorIcon.vue';
import { deleteJson, patchJson, postJson, putJson } from '@/lib/http';

defineOptions({ layout: AppLayout });

const props = defineProps({
    providers: { type: Array, required: true },
    activeProvider: { type: String, required: true },
    vendors: { type: Array, required: true },
    models: { type: Array, required: true },
    capabilities: { type: Array, required: true },
});

const SECTIONS = [
    { key: 'provider', label: 'Провайдер', icon: Server },
    { key: 'models', label: 'Модели и цены', icon: Boxes },
];
const section = ref('provider');
const notice = ref(null);
const flash = (text, error = false) => {
    notice.value = { text, error };
    setTimeout(() => notice.value?.text === text && (notice.value = null), 4000);
};

// ── Провайдер ──────────────────────────────────────────
const active = ref(props.activeProvider);

// Применяем сразу, сохраняем фоном; не принял сервер — откатываем
const activate = async (slug) => {
    const previous = active.value;
    if (slug === previous) return;
    active.value = slug;
    try {
        await putJson('/admin/provider', { slug });
    } catch (e) {
        active.value = previous;
        flash(e.message, true);
    }
};

// ── Модели и цены ──────────────────────────────────────
const tab = ref(props.activeProvider);
const models = reactive(props.models.map((m) => ({ ...m, offerings: { ...m.offerings } })));
const vendorOf = (id) => props.vendors.find((v) => v.id === id);
const PRICE_FIELDS = [
    { key: 'price_input', label: 'Ввод' },
    { key: 'price_output', label: 'Вывод' },
    { key: 'price_cache_read', label: 'Кэш чт.' },
    { key: 'price_cache_write', label: 'Кэш зап.' },
    { key: 'price_web_search', label: 'Поиск' },
];
// Лимиты модели у провайдера, токенов; пусто — неизвестно
const LIMIT_FIELDS = [
    { key: 'context_length', label: 'Контекст' },
    { key: 'max_output_tokens', label: 'Ответ, макс.' },
];
const PREFIX = { xai: 'x-ai' };
const defaultExternalId = (model) => `${PREFIX[vendorOf(model.vendor_id)?.slug] ?? vendorOf(model.vendor_id)?.slug}/${model.slug}`;
const rowError = reactive({});

const saveOffering = async (model, patch = {}) => {
    const previous = model.offerings[tab.value] ? { ...model.offerings[tab.value] } : null;
    const draft = { external_id: defaultExternalId(model), price_currency: 'RUB', is_active: true, ...previous, ...patch };
    // Пустое поле цены — «не знаем», а не ноль
    for (const { key } of [...PRICE_FIELDS, ...LIMIT_FIELDS]) if (draft[key] === '' || draft[key] === undefined) draft[key] = null;

    model.offerings[tab.value] = draft;
    delete rowError[model.id];
    try {
        const { offering } = await putJson(`/admin/providers/${tab.value}/models/${model.id}`, draft);
        model.offerings[tab.value] = offering;
    } catch (e) {
        if (previous) model.offerings[tab.value] = previous;
        else delete model.offerings[tab.value];
        rowError[model.id] = e.message;
    }
};

const disconnect = async (model) => {
    const previous = model.offerings[tab.value];
    delete model.offerings[tab.value];
    try {
        await deleteJson(`/admin/providers/${tab.value}/models/${model.id}`);
    } catch (e) {
        model.offerings[tab.value] = previous;
        rowError[model.id] = e.message;
    }
};

const onField = (model, key, event) => {
    const value = event.target.value.trim();
    if ((model.offerings[tab.value]?.[key] ?? '') === value) return;
    saveOffering(model, { [key]: value === '' ? null : value });
};

const toggleCapability = (model, capability) => {
    const current = model.offerings[tab.value].capabilities ?? [];
    saveOffering(model, {
        capabilities: current.includes(capability) ? current.filter((c) => c !== capability) : [...current, capability],
    });
};

const toggleModel = async (model, value) => {
    model.is_active = value;
    try {
        await patchJson(`/admin/models/${model.id}`, { is_active: value });
    } catch (e) {
        model.is_active = !value;
        rowError[model.id] = e.message;
    }
};

// Новая модель
const creating = ref(false);
const draft = reactive({ vendor_id: props.vendors[0]?.id, name: '', slug: '', description: '' });
const createModel = async () => {
    try {
        const { model } = await postJson('/admin/models', draft);
        models.push({ ...model, offerings: {} });
        Object.assign(draft, { name: '', slug: '', description: '' });
        creating.value = false;
        flash(`Модель «${model.name}» создана — подключите её к провайдеру в таблице`);
    } catch (e) {
        flash(e.message, true);
    }
};

const syncing = ref(false);
const syncPolza = async () => {
    syncing.value = true;
    try {
        const { updated } = await postJson('/admin/providers/polza/sync');
        flash(`Цены, лимиты и возможности обновлены у ${updated} моделей — перезагрузите страницу, чтобы увидеть`);
    } catch (e) {
        flash(e.message, true);
    } finally {
        syncing.value = false;
    }
};

const providerName = computed(() => props.providers.find((p) => p.slug === tab.value)?.name);
</script>

<template>
    <Head title="Админ" />

    <ModeShell wide>
        <div v-frost class="glass flex min-h-0 flex-1 overflow-hidden rounded-[28px]">
            <!-- Разделы -->
            <nav class="w-52 shrink-0 space-y-1 border-r border-white/10 p-3 lg:w-60">
                <button
                    v-for="item in SECTIONS"
                    :key="item.key"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-[15px] transition"
                    :class="section === item.key ? 'glass-inset text-white' : 'text-white/70 hover:bg-white/5 hover:text-white'"
                    @click="section = item.key"
                >
                    <component :is="item.icon" class="size-[18px]" :stroke-width="1.5" />
                    {{ item.label }}
                </button>
            </nav>

            <div class="scrollbar-glass min-w-0 flex-1 overflow-y-auto p-6">
                <p v-if="notice" class="mb-4 rounded-xl px-4 py-2 text-sm" :class="notice.error ? 'bg-hot/15 text-hot-soft' : 'bg-cool/15 text-cool-soft'">
                    {{ notice.text }}
                </p>

                <!-- Провайдер -->
                <section v-if="section === 'provider'" class="max-w-3xl space-y-4">
                    <header>
                        <h2 class="text-2xl font-light">Провайдер</h2>
                        <p class="mt-1 text-sm text-white/55">
                            Через кого идут запросы к моделям. В чате видны только модели, подключённые у выбранного провайдера.
                        </p>
                    </header>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="provider in providers"
                            :key="provider.slug"
                            type="button"
                            class="glass-inset rounded-2xl p-4 text-left transition"
                            :class="active === provider.slug ? 'ring-1 ring-cool/70 shadow-[0_0_24px_rgba(91,140,255,0.25)]' : 'hover:bg-white/8'"
                            @click="activate(provider.slug)"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-lg">{{ provider.name }}</span>
                                <span v-if="active === provider.slug" class="flex items-center gap-1 rounded-full bg-cool/20 px-2.5 py-0.5 text-xs text-cool-soft">
                                    <Check class="size-3.5" :stroke-width="2" /> Активен
                                </span>
                            </div>
                            <div class="mt-3 space-y-1 text-sm text-white/60">
                                <div>Моделей подключено: {{ provider.models_count }}</div>
                                <div class="flex items-center gap-1.5" :class="provider.has_key ? 'text-emerald-300/80' : 'text-hot-soft'">
                                    <KeyRound class="size-3.5" :stroke-width="1.5" />
                                    {{ provider.has_key ? 'Ключ задан' : 'Нет ключа в .env — запросы не пройдут' }}
                                </div>
                            </div>
                        </button>
                    </div>
                </section>

                <!-- Модели и цены -->
                <section v-else class="space-y-4">
                    <header class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 class="text-2xl font-light">Модели и цены</h2>
                            <p class="mt-1 text-sm text-white/55">
                                Цены — ₽ за 1M токенов, поиск — ₽ за запрос. Пустая цена — «не знаем»: модель работает,
                                стоимость берётся от провайдера (Polza сообщает её сама) или не показывается.
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button
                                v-if="tab === 'polza'"
                                type="button"
                                :disabled="syncing"
                                class="glass-inset flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-white/80 hover:text-white disabled:opacity-50"
                                @click="syncPolza"
                            >
                                <RefreshCw class="size-4" :class="{ 'animate-spin': syncing }" :stroke-width="1.5" />
                                Цены и возможности из каталога Polza
                            </button>
                            <button type="button" class="glass-inset flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-white/80 hover:text-white" @click="creating = !creating">
                                <Plus class="size-4" :stroke-width="1.5" /> Модель
                            </button>
                        </div>
                    </header>

                    <!-- Новая модель -->
                    <form v-if="creating" class="glass-inset grid gap-2 rounded-2xl p-4 sm:grid-cols-[10rem_1fr_1fr_1.5fr_auto]" @submit.prevent="createModel">
                        <select v-model="draft.vendor_id" class="glass-inset rounded-xl px-3 py-2 text-sm outline-none">
                            <option v-for="v in vendors" :key="v.id" :value="v.id" class="bg-[#2c3445]">{{ v.product || v.name }}</option>
                        </select>
                        <input v-model="draft.name" required placeholder="Название: Claude Opus 6" class="glass-inset rounded-xl px-3 py-2 text-sm outline-none" />
                        <input v-model="draft.slug" required placeholder="slug: claude-opus-6" class="glass-inset rounded-xl px-3 py-2 font-mono text-sm outline-none" />
                        <input v-model="draft.description" placeholder="Описание (необязательно)" class="glass-inset rounded-xl px-3 py-2 text-sm outline-none" />
                        <button type="submit" class="rounded-xl bg-cool px-4 py-2 text-sm text-white">Создать</button>
                    </form>

                    <!-- Вкладки провайдеров -->
                    <div class="glass-inset inline-flex rounded-2xl p-1">
                        <button
                            v-for="provider in providers"
                            :key="provider.slug"
                            type="button"
                            class="rounded-xl px-4 py-1.5 text-sm transition"
                            :class="tab === provider.slug ? 'bg-white/15 text-white' : 'text-white/60 hover:text-white'"
                            @click="tab = provider.slug"
                        >
                            {{ provider.name }}
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[80rem] text-sm">
                            <thead class="text-left text-xs text-white/45">
                                <tr>
                                    <th class="py-2 pr-3 font-normal">Модель</th>
                                    <th class="py-2 pr-3 font-normal">ID у {{ providerName }}</th>
                                    <th v-if="tab === 'polza'" class="py-2 pr-3 font-normal" title="Внутренний поставщик Polza: openai/flex, amazon-bedrock…">Источник</th>
                                    <th v-for="field in PRICE_FIELDS" :key="field.key" class="w-24 py-2 pr-3 font-normal">{{ field.label }}</th>
                                    <th v-for="field in LIMIT_FIELDS" :key="field.key" class="w-24 py-2 pr-3 font-normal">{{ field.label }}</th>
                                    <th class="py-2 pr-3 font-normal" title="Что модель умеет у этого провайдера: параметры, которые она не принимает, в запрос не уходят">Умеет</th>
                                    <th class="py-2 pr-3 font-normal">В чате</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="model in models" :key="model.id">
                                    <tr class="border-t border-white/6" :class="{ 'opacity-50': !model.is_active }">
                                        <td class="py-2 pr-3">
                                            <div class="flex items-center gap-2">
                                                <VendorIcon :slug="vendorOf(model.vendor_id)?.slug ?? ''" />
                                                <div class="min-w-0">
                                                    <div class="truncate">{{ model.name }}</div>
                                                    <div class="truncate font-mono text-xs text-white/40">{{ model.slug }}</div>
                                                </div>
                                            </div>
                                        </td>

                                        <template v-if="model.offerings[tab]">
                                            <td class="py-2 pr-3">
                                                <input
                                                    :value="model.offerings[tab].external_id"
                                                    class="glass-inset w-full min-w-44 rounded-lg px-2 py-1.5 font-mono text-xs outline-none focus:border-cool/60"
                                                    @change="onField(model, 'external_id', $event)"
                                                />
                                            </td>
                                            <td v-if="tab === 'polza'" class="py-2 pr-3">
                                                <input
                                                    :value="model.offerings[tab].upstream"
                                                    placeholder="авто"
                                                    class="glass-inset w-full min-w-28 rounded-lg px-2 py-1.5 font-mono text-xs outline-none placeholder:text-white/30 focus:border-cool/60"
                                                    @change="onField(model, 'upstream', $event)"
                                                />
                                            </td>
                                            <td v-for="field in PRICE_FIELDS" :key="field.key" class="py-2 pr-3">
                                                <input
                                                    :value="model.offerings[tab][field.key] === null ? '' : Number(model.offerings[tab][field.key])"
                                                    inputmode="decimal"
                                                    placeholder="—"
                                                    class="glass-inset w-full rounded-lg px-2 py-1.5 text-right tabular-nums outline-none placeholder:text-white/30 focus:border-cool/60"
                                                    @change="onField(model, field.key, $event)"
                                                />
                                            </td>
                                            <td v-for="field in LIMIT_FIELDS" :key="field.key" class="py-2 pr-3">
                                                <input
                                                    :value="model.offerings[tab][field.key] ?? ''"
                                                    inputmode="numeric"
                                                    placeholder="—"
                                                    class="glass-inset w-full rounded-lg px-2 py-1.5 text-right tabular-nums outline-none placeholder:text-white/30 focus:border-cool/60"
                                                    @change="onField(model, field.key, $event)"
                                                />
                                            </td>
                                            <td class="py-2 pr-3">
                                                <div class="flex flex-wrap gap-1">
                                                    <button
                                                        v-for="capability in capabilities"
                                                        :key="capability.value"
                                                        type="button"
                                                        class="rounded-full px-2 py-0.5 text-xs whitespace-nowrap transition"
                                                        :class="model.offerings[tab].capabilities?.includes(capability.value)
                                                            ? 'bg-cool/25 text-cool-soft'
                                                            : 'text-white/35 ring-1 ring-white/10 hover:text-white/70'"
                                                        @click="toggleCapability(model, capability.value)"
                                                    >
                                                        {{ capability.label }}
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="py-2 pr-3">
                                                <GlassToggle
                                                    :model-value="model.offerings[tab].is_active && model.is_active"
                                                    label=""
                                                    @update:model-value="(v) => (model.is_active ? saveOffering(model, { is_active: v }) : toggleModel(model, v))"
                                                />
                                            </td>
                                            <td class="py-2 text-right">
                                                <button type="button" class="rounded-lg p-1.5 text-white/40 hover:bg-white/5 hover:text-hot-soft" title="Отключить от провайдера" @click="disconnect(model)">
                                                    <X class="size-4" :stroke-width="1.5" />
                                                </button>
                                            </td>
                                        </template>

                                        <!-- Модель есть, но у этого провайдера не подключена -->
                                        <td v-else :colspan="PRICE_FIELDS.length + LIMIT_FIELDS.length + (tab === 'polza' ? 5 : 4)" class="py-2 pr-3">
                                            <button type="button" class="flex items-center gap-2 text-white/50 hover:text-white" @click="saveOffering(model)">
                                                <Plus class="size-4" :stroke-width="1.5" />
                                                Подключить к {{ providerName }} как <span class="font-mono text-xs">{{ defaultExternalId(model) }}</span>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="rowError[model.id]">
                                        <td :colspan="PRICE_FIELDS.length + LIMIT_FIELDS.length + 6" class="pb-2 text-xs text-hot-soft">{{ rowError[model.id] }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </ModeShell>
</template>
