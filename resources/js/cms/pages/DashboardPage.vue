<script setup>
import { computed, onMounted, ref } from 'vue';
import StateBox from '../components/StateBox.vue';
import RoleBadge from '../components/RoleBadge.vue';
import { getDashboard } from '../api';
import { useAuthStore } from '../stores/auth';
import { parseApiError } from '../utils/errors';
import { formatDateTime } from '../utils/format';

const auth = useAuthStore();

const state = ref('loading');
const errorMessage = ref('');
const data = ref(null);

const cards = computed(() => {
    const stats = data.value?.stats;

    if (!stats) return [];

    return [
        { key: 'total', label: 'Total Users', value: stats.total, accent: 'text-slate-900' },
        { key: 'active', label: 'Active Users', value: stats.active, accent: 'text-emerald-600' },
        { key: 'inactive', label: 'Inactive Users', value: stats.inactive, accent: 'text-amber-600' },
        { key: 'blocked', label: 'Blocked Users', value: stats.blocked, accent: 'text-red-600' },
    ];
});

async function load() {
    state.value = 'loading';
    errorMessage.value = '';

    try {
        data.value = await getDashboard();

        // Đồng bộ thông tin mới nhất (avatar, phone...) vào store để sidebar hiển thị
        auth.setUser(data.value.me);
        state.value = 'ready';
    } catch (e) {
        errorMessage.value = parseApiError(e).message;
        state.value = 'error';
    }
}

onMounted(load);
</script>

<template>
    <section>
        <h1 class="text-2xl font-semibold text-slate-900">Dashboard</h1>

        <StateBox v-if="state === 'loading'" kind="loading" />
        <StateBox v-else-if="state === 'error'" kind="error" :message="errorMessage" @retry="load" />

        <template v-else>
            <p class="mt-1 text-sm text-slate-500">Welcome back, {{ data.me.name }}.</p>

            <div v-if="cards.length" class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="stat-cards">
                <div v-for="card in cards" :key="card.key" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" :data-testid="`card-${card.key}`">
                    <p class="text-sm text-slate-500">{{ card.label }}</p>
                    <p class="mt-2 text-3xl font-semibold" :class="card.accent">{{ card.value }}</p>
                </div>
            </div>

            <div class="mt-6 max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-sm" data-testid="me-card">
                <h2 class="text-sm font-medium text-slate-500">Your account</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Name</dt><dd class="text-slate-900">{{ data.me.name }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Email</dt><dd class="truncate text-slate-900">{{ data.me.email }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Role</dt><dd><RoleBadge :role="data.me.role" /></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Last login</dt><dd class="text-slate-900">{{ formatDateTime(data.me.last_login_at) }}</dd></div>
                </dl>
            </div>
        </template>
    </section>
</template>
