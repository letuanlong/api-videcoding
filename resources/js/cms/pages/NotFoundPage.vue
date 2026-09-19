<script setup>
import { RouterLink } from 'vue-router';
</script>

<template>
    <section class="mx-auto max-w-md px-4 py-24 text-center" data-testid="not-found-page">
        <p class="text-5xl font-semibold text-slate-300">404</p>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">Page not found</h1>
        <p class="mt-2 text-sm text-slate-500">The page you are looking for does not exist.</p>
        <RouterLink :to="{ name: 'dashboard' }" class="mt-6 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            Go to dashboard
        </RouterLink>
    </section>
</template>
