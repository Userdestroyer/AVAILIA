<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppBackground from '@/components/AppBackground.vue';
import Wordmark from '@/components/Wordmark.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Вход" />
    <AppBackground :thinking="form.processing" />

    <main class="flex min-h-dvh items-center justify-center px-4">
        <form v-frost class="glass w-full max-w-sm space-y-5 rounded-[32px] p-8" @submit.prevent="submit">
            <h1 class="pb-2 text-center text-4xl">
                <Wordmark />
            </h1>

            <div class="space-y-1.5">
                <label for="email" class="block px-1 text-sm text-fg/60">Почта</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    required
                    autofocus
                    class="glass-inset w-full rounded-2xl px-4 py-3 font-light outline-none transition focus:border-cool/60 focus:bg-fg/10"
                />
                <p v-if="form.errors.email" class="px-1 text-sm text-hot-soft">{{ form.errors.email }}</p>
            </div>

            <div class="space-y-1.5">
                <label for="password" class="block px-1 text-sm text-fg/60">Пароль</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="glass-inset w-full rounded-2xl px-4 py-3 font-light outline-none transition focus:border-cool/60 focus:bg-fg/10"
                />
                <p v-if="form.errors.password" class="px-1 text-sm text-hot-soft">{{ form.errors.password }}</p>
            </div>

            <label class="flex cursor-pointer items-center gap-2 px-1 text-sm text-fg/60">
                <input v-model="form.remember" type="checkbox" class="accent-cool" />
                Запомнить меня
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-2xl bg-gradient-to-b from-(--accent-hi) to-cool py-3 font-normal text-white shadow-lg shadow-cool/40 transition hover:brightness-110 disabled:opacity-50"
            >
                Войти
            </button>
        </form>
    </main>
</template>
