<script setup>
import { RouterLink } from 'vue-router';
</script>

<template>
    <section class="mx-auto max-w-md py-16 text-center" data-testid="forbidden-page">
        <p class="text-5xl font-semibold text-slate-300">403</p>
        <h1 class="mt-4 text-xl font-semibold text-slate-900">Access denied</h1>
        <p class="mt-2 text-sm text-slate-500">You do not have permission to view this page.</p>
        <RouterLink :to="{ name: 'dashboard' }" class="mt-6 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            Back to dashboard
        </RouterLink>
    </section>
</template>
