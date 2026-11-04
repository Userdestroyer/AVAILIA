<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const user = computed(() => usePage().props.auth.user);
</script>

<template>
    <Head title="Главная" />

    <main class="mx-auto max-w-2xl space-y-4 px-4 py-12">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Привет, {{ user.username }}</h1>
            <Link href="/logout" method="post" as="button" class="text-sm text-zinc-400 hover:text-zinc-100">
                Выйти
            </Link>
        </div>

        <p class="text-zinc-400">
            Тариф: {{ user.plan.name }} · Роль: {{ user.role }} · Валюта: {{ user.currency }}
        </p>
    </main>
</template>
