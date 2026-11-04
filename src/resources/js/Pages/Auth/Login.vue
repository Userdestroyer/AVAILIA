<script setup>
import { Head, useForm } from '@inertiajs/vue3';

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

    <main class="flex min-h-screen items-center justify-center px-4">
        <form class="w-full max-w-sm space-y-4" @submit.prevent="submit">
            <h1 class="text-2xl font-semibold">AVAILIA</h1>

            <div class="space-y-1">
                <label for="email" class="block text-sm text-zinc-400">Почта</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    required
                    autofocus
                    class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 outline-none focus:border-zinc-400"
                />
                <p v-if="form.errors.email" class="text-sm text-red-400">{{ form.errors.email }}</p>
            </div>

            <div class="space-y-1">
                <label for="password" class="block text-sm text-zinc-400">Пароль</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 outline-none focus:border-zinc-400"
                />
                <p v-if="form.errors.password" class="text-sm text-red-400">{{ form.errors.password }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm text-zinc-400">
                <input v-model="form.remember" type="checkbox" />
                Запомнить меня
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-zinc-100 px-3 py-2 font-medium text-zinc-900 disabled:opacity-50"
            >
                Войти
            </button>
        </form>
    </main>
</template>
