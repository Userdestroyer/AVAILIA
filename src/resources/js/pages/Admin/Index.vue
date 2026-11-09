<script setup>
import { Deferred, Head, usePage } from '@inertiajs/vue3';
import { Boxes, Check, Gauge, KeyRound, Plus, RefreshCw, Server, Users, X, Zap } from 'lucide-vue-next';
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
    serviceModel: { type: Number, default: null },
    plans: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    // Догружается после открытия страницы
    speed: { type: Object, default: null },
});

const SECTIONS = [
    { key: 'provider', label: 'Провайдер', icon: Server },
    { key: 'models', label: 'Модели и цены', icon: Boxes },
    { key: 'plans', label: 'Тарифы', icon: Zap },
    { key: 'users', label: 'Пользователи', icon: Users },
    { key: 'speed', label: 'Скорость', icon: Gauge },
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

// Сервисная модель: фоновые задачи (название чата и т.п.) — только подключённые у активного провайдера
const serviceModel = ref(props.serviceModel);
const serviceCandidates = computed(() => props.models.filter((m) => m.is_active && m.offerings[active.value]?.is_active));
const chooseServiceModel = async (id) => {
    const previous = serviceModel.value;
    serviceModel.value = id;
    try {
        await putJson('/admin/service-model', { ai_model_id: id });
    } catch (e) {
        serviceModel.value = previous;
        flash(e.message, true);
    }
};

// ── Тарифы ─────────────────────────────────────────────
// Неделя = месяц ÷ 4,345, сессия = неделя ÷ сессий в неделю (как Plan::windowLimit) — видно при вводе
const WEEKS_PER_MONTH = 4.345;
const energyRate = usePage().props.energyRate;
const plans = reactive(props.plans.map((p) => ({ ...p })));
const PLAN_FIELDS = [
    { key: 'text_monthly', label: 'Текст, ₽/мес' },
    { key: 'agent_monthly', label: 'Агенты, ₽/мес' },
    { key: 'content_tokens_monthly', label: 'Токены/мес' },
    { key: 'sessions_per_week', label: 'Сессий в неделю' },
    { key: 'session_hours', label: 'Часов в сессии' },
];
const windowsOf = (plan, key) => {
    if (plan[key] === null || plan[key] === '') return null;
    const week = Number(plan[key]) / WEEKS_PER_MONTH;
    return { week, session: week / Math.max(0.1, Number(plan.sessions_per_week)) };
};
const windowMoney = (value) => `${Math.round(value * 100) / 100} ₽ · ⚡${Math.round(value * energyRate)}`;
const planError = reactive({});

const savePlan = async (plan, key, event) => {
    const raw = event.target.value.trim();
    const value = raw === '' ? null : Number(raw.replace(',', '.'));
    if (value === plan[key] || (value !== null && Number.isNaN(value))) return;
    const previous = plan[key];
    plan[key] = value;
    delete planError[plan.id];
    try {
        await patchJson(`/admin/plans/${plan.id}`, { [key]: value });
    } catch (e) {
        plan[key] = previous;
        event.target.value = previous ?? '';
        planError[plan.id] = e.message;
    }
};

// ── Пользователи ───────────────────────────────────────
const users = reactive(props.users.map((u) => ({ ...u, grant: '' })));
const userError = reactive({});

const changePlan = async (user, planId) => {
    const previous = user.plan_id;
    user.plan_id = planId;
    try {
        const { content_tokens } = await patchJson(`/admin/users/${user.id}`, { plan_id: planId });
        user.content_tokens = content_tokens;
    } catch (e) {
        user.plan_id = previous;
        userError[user.id] = e.message;
    }
};

const grantTokens = async (user) => {
    const amount = Number(user.grant);
    if (!amount) return;
    user.content_tokens += amount;
    user.grant = '';
    try {
        const { content_tokens } = await postJson(`/admin/users/${user.id}/tokens`, { amount });
        user.content_tokens = content_tokens;
        flash(`${user.username}: начислено ${amount} токенов`);
    } catch (e) {
        user.content_tokens -= amount;
        userError[user.id] = e.message;
    }
};

// ── Скорость ───────────────────────────────────────────
const ms = (value) => (value === null || value === undefined ? '—' : value >= 1000 ? `${(value / 1000).toFixed(1)} с` : `${value} мс`);
const PURPOSES = { chat_title: 'Название чата' };
const money = (cost) => Object.entries(cost).map(([currency, amount]) => `${Number(amount).toFixed(2)} ${currency === 'RUB' ? '₽' : currency}`).join(' + ') || '—';

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
            <nav class="w-52 shrink-0 space-y-1 border-r border-fg/10 p-3 lg:w-60">
                <button
                    v-for="item in SECTIONS"
                    :key="item.key"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-[15px] transition"
                    :class="section === item.key ? 'glass-inset text-fg' : 'text-fg/70 hover:bg-fg/5 hover:text-fg'"
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
                        <p class="mt-1 text-sm text-fg/55">
                            Через кого идут запросы к моделям. В чате видны только модели, подключённые у выбранного провайдера.
                        </p>
                    </header>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="provider in providers"
                            :key="provider.slug"
                            type="button"
                            class="glass-inset rounded-2xl p-4 text-left transition"
                            :class="active === provider.slug ? 'ring-1 ring-cool/70 shadow-[0_0_24px_rgba(91,140,255,0.25)]' : 'hover:bg-fg/8'"
                            @click="activate(provider.slug)"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-lg">{{ provider.name }}</span>
                                <span v-if="active === provider.slug" class="flex items-center gap-1 rounded-full bg-cool/20 px-2.5 py-0.5 text-xs text-cool-soft">
                                    <Check class="size-3.5" :stroke-width="2" /> Активен
                                </span>
                            </div>
                            <div class="mt-3 space-y-1 text-sm text-fg/60">
                                <div>Моделей подключено: {{ provider.models_count }}</div>
                                <div class="flex items-center gap-1.5" :class="provider.has_key ? 'text-emerald-300/80' : 'text-hot-soft'">
                                    <KeyRound class="size-3.5" :stroke-width="1.5" />
                                    {{ provider.has_key ? 'Ключ задан' : 'Нет ключа в .env — запросы не пройдут' }}
                                </div>
                            </div>
                        </button>
                    </div>

                    <div class="glass-inset space-y-2 rounded-2xl p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="text-[15px]">Сервисная модель</div>
                                <p class="mt-0.5 text-sm text-fg/55">Фоновые задачи: название чата, дальше — теги, подбор картриджей, диффы. Быстрая и дешёвая.</p>
                            </div>
                            <select
                                :value="serviceModel"
                                class="glass-inset rounded-xl px-3 py-2 text-sm outline-none"
                                @change="chooseServiceModel(Number($event.target.value))"
                            >
                                <option v-for="m in serviceCandidates" :key="m.id" :value="m.id" class="bg-(--popover-bg)">{{ m.name }}</option>
                            </select>
                        </div>
                        <p v-if="serviceModel && !serviceCandidates.some((m) => m.id === serviceModel)" class="text-xs text-hot-soft">
                            Выбранная модель не подключена у активного провайдера — фоновые задачи сейчас не выполняются.
                        </p>
                    </div>
                </section>

                <!-- Тарифы -->
                <section v-else-if="section === 'plans'" class="max-w-5xl space-y-4">
                    <header>
                        <h2 class="text-2xl font-light">Тарифы</h2>
                        <p class="mt-1 text-sm text-fg/55">
                            Лимиты в ₽ себестоимости — юзер видит энергию (⚡{{ energyRate }} за 1 ₽). Неделя = месяц ÷ 4,345, сессия = неделя ÷ сессий
                            в неделю: меньше сессий — каждая заметнее. Пусто — без ограничений. Custom — для калибровки, правки не задевают остальных.
                        </p>
                    </header>

                    <div v-for="plan in plans" :key="plan.id" class="glass-inset space-y-3 rounded-2xl p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-lg">{{ plan.name }} <span class="font-mono text-xs text-fg/40">{{ plan.code }}</span></span>
                            <span class="text-xs text-fg/50">пользователей: {{ plan.users_count }}</span>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-5">
                            <label v-for="field in PLAN_FIELDS" :key="field.key" class="block">
                                <span class="text-xs text-fg/55">{{ field.label }}</span>
                                <input
                                    :value="plan[field.key] === null ? '' : Number(plan[field.key])"
                                    inputmode="decimal"
                                    placeholder="∞"
                                    class="glass-inset mt-1 w-full rounded-lg px-2 py-1.5 text-right tabular-nums outline-none placeholder:text-fg/30 focus:border-cool/60"
                                    @change="savePlan(plan, field.key, $event)"
                                />
                            </label>
                        </div>
                        <div class="grid gap-1 text-xs text-fg/60 sm:grid-cols-2">
                            <span v-for="[key, label] in [['text_monthly', 'Текст'], ['agent_monthly', 'Агенты']]" :key="key">
                                {{ label }}:
                                <template v-if="windowsOf(plan, key)">неделя {{ windowMoney(windowsOf(plan, key).week) }}, сессия {{ windowMoney(windowsOf(plan, key).session) }}</template>
                                <template v-else>без ограничений</template>
                            </span>
                        </div>
                        <p v-if="planError[plan.id]" class="text-xs text-hot-soft">{{ planError[plan.id] }}</p>
                    </div>
                </section>

                <!-- Пользователи -->
                <section v-else-if="section === 'users'" class="space-y-4">
                    <header>
                        <h2 class="text-2xl font-light">Пользователи</h2>
                        <p class="mt-1 text-sm text-fg/55">Тариф, трата текстовой недели и баланс токенов контента. Токены можно начислить любому, в том числе себе.</p>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[46rem] text-sm">
                            <thead class="text-left text-xs text-fg/45">
                                <tr>
                                    <th class="py-2 pr-3 font-normal">Пользователь</th>
                                    <th class="py-2 pr-3 font-normal">Тариф</th>
                                    <th class="py-2 pr-3 font-normal">Текст за неделю</th>
                                    <th class="py-2 pr-3 font-normal">Токены</th>
                                    <th class="py-2 font-normal">Начислить</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="user in users" :key="user.id">
                                    <tr class="border-t border-fg/6">
                                        <td class="py-2 pr-3">
                                            <div>{{ user.username }} <span v-if="user.role === 'admin'" class="text-xs text-cool-soft">админ</span></div>
                                            <div class="text-xs text-fg/40">{{ user.email }}</div>
                                        </td>
                                        <td class="py-2 pr-3">
                                            <select :value="user.plan_id" class="glass-inset rounded-lg px-2 py-1.5 outline-none" @change="changePlan(user, Number($event.target.value))">
                                                <option v-for="plan in plans" :key="plan.id" :value="plan.id" class="bg-(--popover-bg)">{{ plan.name }}</option>
                                            </select>
                                        </td>
                                        <td class="py-2 pr-3 tabular-nums">
                                            <span :title="`${user.week_spent} ₽`">⚡{{ Math.round(user.week_spent * energyRate) }}</span>
                                            <span class="text-fg/40"> / {{ user.week_limit === null ? '∞' : `⚡${Math.round(user.week_limit * energyRate)}` }}</span>
                                        </td>
                                        <td class="py-2 pr-3 tabular-nums">{{ user.content_tokens }}</td>
                                        <td class="py-2">
                                            <form class="flex gap-1.5" @submit.prevent="grantTokens(user)">
                                                <input v-model="user.grant" inputmode="numeric" placeholder="+0" class="glass-inset w-20 rounded-lg px-2 py-1.5 text-right tabular-nums outline-none placeholder:text-fg/30" />
                                                <button type="submit" :disabled="!Number(user.grant)" class="rounded-lg bg-cool px-2.5 py-1.5 text-xs text-white disabled:opacity-40">Начислить</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr v-if="userError[user.id]">
                                        <td colspan="5" class="pb-2 text-xs text-hot-soft">{{ userError[user.id] }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Скорость -->
                <section v-else-if="section === 'speed'" class="max-w-5xl space-y-4">
                    <header>
                        <h2 class="text-2xl font-light">Скорость</h2>
                        <p class="mt-1 text-sm text-fg/55">
                            TTFT — время до первого видимого токена. «Гейтвей» — от подключения к стриму, «Человек» — от нажатия
                            «Отправить» до токена на экране: разница — накладные расходы на нашей стороне.
                        </p>
                    </header>

                    <Deferred data="speed">
                        <template #fallback>
                            <p class="text-sm text-fg/45">Считаю…</p>
                        </template>

                        <p class="text-xs text-fg/45">За {{ speed.days }} дней, медиана и p90</p>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[44rem] text-sm tabular-nums">
                                <thead class="text-left text-xs text-fg/45">
                                    <tr>
                                        <th class="py-2 pr-3 font-normal">Модель</th>
                                        <th class="py-2 pr-3 font-normal">Ответов</th>
                                        <th class="py-2 pr-3 font-normal">TTFT гейтвей</th>
                                        <th class="py-2 pr-3 font-normal">TTFT человек</th>
                                        <th class="py-2 pr-3 font-normal">Генерация</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in speed.models" :key="row.provider + row.model" class="border-t border-fg/6">
                                        <td class="py-2 pr-3">{{ row.model }} <span class="text-xs text-fg/40">· {{ row.provider }}</span></td>
                                        <td class="py-2 pr-3">{{ row.count }}</td>
                                        <td class="py-2 pr-3">{{ ms(row.ttft_p50) }} <span class="text-fg/40">/ {{ ms(row.ttft_p90) }}</span></td>
                                        <td class="py-2 pr-3">{{ ms(row.client_p50) }} <span class="text-fg/40">/ {{ ms(row.client_p90) }}</span></td>
                                        <td class="py-2 pr-3">{{ ms(row.duration_p50) }}</td>
                                    </tr>
                                    <tr v-if="!speed.models.length">
                                        <td colspan="5" class="py-3 text-fg/45">Замеров пока нет — появятся с новыми ответами.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <h3 class="pt-4 text-lg font-light">Фоновые вызовы</h3>
                        <table class="w-full max-w-2xl text-sm tabular-nums">
                            <thead class="text-left text-xs text-fg/45">
                                <tr>
                                    <th class="py-2 pr-3 font-normal">Задача</th>
                                    <th class="py-2 pr-3 font-normal">Вызовов</th>
                                    <th class="py-2 pr-3 font-normal">Ошибок</th>
                                    <th class="py-2 pr-3 font-normal">Расход</th>
                                    <th class="py-2 pr-3 font-normal">Время</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in speed.service" :key="row.purpose" class="border-t border-fg/6">
                                    <td class="py-2 pr-3">{{ PURPOSES[row.purpose] ?? row.purpose }}</td>
                                    <td class="py-2 pr-3">{{ row.count }}</td>
                                    <td class="py-2 pr-3" :class="{ 'text-hot-soft': row.failed }">{{ row.failed }}</td>
                                    <td class="py-2 pr-3">{{ money(row.cost) }}</td>
                                    <td class="py-2 pr-3">{{ ms(row.duration_p50) }}</td>
                                </tr>
                                <tr v-if="!speed.service.length">
                                    <td colspan="5" class="py-3 text-fg/45">Фоновых вызовов пока не было.</td>
                                </tr>
                            </tbody>
                        </table>
                    </Deferred>
                </section>

                <!-- Модели и цены -->
                <section v-else class="space-y-4">
                    <header class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 class="text-2xl font-light">Модели и цены</h2>
                            <p class="mt-1 text-sm text-fg/55">
                                Цены — ₽ за 1M токенов, поиск — ₽ за запрос. Пустая цена — «не знаем»: модель работает,
                                стоимость берётся от провайдера (Polza сообщает её сама) или не показывается.
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button
                                v-if="tab === 'polza'"
                                type="button"
                                :disabled="syncing"
                                class="glass-inset flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-fg/80 hover:text-fg disabled:opacity-50"
                                @click="syncPolza"
                            >
                                <RefreshCw class="size-4" :class="{ 'animate-spin': syncing }" :stroke-width="1.5" />
                                Цены и возможности из каталога Polza
                            </button>
                            <button type="button" class="glass-inset flex items-center gap-2 rounded-xl px-3 py-2 text-sm text-fg/80 hover:text-fg" @click="creating = !creating">
                                <Plus class="size-4" :stroke-width="1.5" /> Модель
                            </button>
                        </div>
                    </header>

                    <!-- Новая модель -->
                    <form v-if="creating" class="glass-inset grid gap-2 rounded-2xl p-4 sm:grid-cols-[10rem_1fr_1fr_1.5fr_auto]" @submit.prevent="createModel">
                        <select v-model="draft.vendor_id" class="glass-inset rounded-xl px-3 py-2 text-sm outline-none">
                            <option v-for="v in vendors" :key="v.id" :value="v.id" class="bg-(--popover-bg)">{{ v.product || v.name }}</option>
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
                            :class="tab === provider.slug ? 'pill-active' : 'text-fg/60 hover:text-fg'"
                            @click="tab = provider.slug"
                        >
                            {{ provider.name }}
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[80rem] text-sm">
                            <thead class="text-left text-xs text-fg/45">
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
                                    <tr class="border-t border-fg/6" :class="{ 'opacity-50': !model.is_active }">
                                        <td class="py-2 pr-3">
                                            <div class="flex items-center gap-2">
                                                <VendorIcon :slug="vendorOf(model.vendor_id)?.slug ?? ''" />
                                                <div class="min-w-0">
                                                    <div class="truncate">{{ model.name }}</div>
                                                    <div class="truncate font-mono text-xs text-fg/40">{{ model.slug }}</div>
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
                                                    class="glass-inset w-full min-w-28 rounded-lg px-2 py-1.5 font-mono text-xs outline-none placeholder:text-fg/30 focus:border-cool/60"
                                                    @change="onField(model, 'upstream', $event)"
                                                />
                                            </td>
                                            <td v-for="field in PRICE_FIELDS" :key="field.key" class="py-2 pr-3">
                                                <input
                                                    :value="model.offerings[tab][field.key] === null ? '' : Number(model.offerings[tab][field.key])"
                                                    inputmode="decimal"
                                                    placeholder="—"
                                                    class="glass-inset w-full rounded-lg px-2 py-1.5 text-right tabular-nums outline-none placeholder:text-fg/30 focus:border-cool/60"
                                                    @change="onField(model, field.key, $event)"
                                                />
                                            </td>
                                            <td v-for="field in LIMIT_FIELDS" :key="field.key" class="py-2 pr-3">
                                                <input
                                                    :value="model.offerings[tab][field.key] ?? ''"
                                                    inputmode="numeric"
                                                    placeholder="—"
                                                    class="glass-inset w-full rounded-lg px-2 py-1.5 text-right tabular-nums outline-none placeholder:text-fg/30 focus:border-cool/60"
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
                                                            : 'text-fg/35 ring-1 ring-fg/10 hover:text-fg/70'"
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
                                                <button type="button" class="rounded-lg p-1.5 text-fg/40 hover:bg-fg/5 hover:text-hot-soft" title="Отключить от провайдера" @click="disconnect(model)">
                                                    <X class="size-4" :stroke-width="1.5" />
                                                </button>
                                            </td>
                                        </template>

                                        <!-- Модель есть, но у этого провайдера не подключена -->
                                        <td v-else :colspan="PRICE_FIELDS.length + LIMIT_FIELDS.length + (tab === 'polza' ? 5 : 4)" class="py-2 pr-3">
                                            <button type="button" class="flex items-center gap-2 text-fg/50 hover:text-fg" @click="saveOffering(model)">
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
