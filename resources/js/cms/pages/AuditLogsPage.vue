<script setup>
import { onMounted, reactive, ref } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import StateBox from '../components/StateBox.vue';
import PaginationBar from '../components/PaginationBar.vue';
import { inputClass } from '../components/ui';
import { listAuditLogs } from '../api';
import { parseApiError } from '../utils/errors';
import { formatDateTime } from '../utils/format';

// Khớp AuditAction ở backend
const ACTIONS = [
    'LOGIN', 'LOGOUT',
    'CREATE_USER', 'UPDATE_USER', 'DELETE_USER',
    'CHANGE_STATUS', 'RESET_PASSWORD',
    'UPDATE_PROFILE', 'CHANGE_PASSWORD',
];

const items = ref([]);
const meta = ref(null);
const state = ref('loading');
const errorMessage = ref('');
const filterError = ref('');

const filters = reactive({ user: '', action: '', date_from: '', date_to: '' });
const paging = reactive({ page: 1, per_page: 20 });

let requestSeq = 0;

async function load() {
    const seq = ++requestSeq;

    state.value = 'loading';
    errorMessage.value = '';

    try {
        const response = await listAuditLogs({ ...filters, ...paging });

        if (seq !== requestSeq) return;

        items.value = response.data;
        meta.value = response.meta;
        state.value = 'ready';
    } catch (e) {
        if (seq !== requestSeq) return;

        const parsed = parseApiError(e);

        errorMessage.value = parsed.message;
        state.value = 'error';
    }
}

function apply() {
    filterError.value = '';

    if (filters.date_from && filters.date_to && filters.date_to < filters.date_from) {
        filterError.value = 'The end date must not be before the start date.';

        return;
    }

    paging.page = 1;
    load();
}

function reset() {
    Object.assign(filters, { user: '', action: '', date_from: '', date_to: '' });
    filterError.value = '';
    paging.page = 1;
    load();
}

function goToPage(page) {
    paging.page = page;
    load();
}

function changePerPage(perPage) {
    paging.per_page = perPage;
    paging.page = 1;
    load();
}

const pretty = (values) => JSON.stringify(values, null, 2);

onMounted(load);
</script>

<template>
    <section>
        <h1 class="text-2xl font-semibold text-slate-900">Audit Logs</h1>

        <form class="mt-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <div>
                <label for="al-user" class="mb-1 block text-sm font-medium text-slate-700">User ID</label>
                <input id="al-user" v-model="filters.user" type="number" min="1" :class="inputClass()" data-testid="al-user" />
            </div>
            <div>
                <label for="al-action" class="mb-1 block text-sm font-medium text-slate-700">Action</label>
                <select id="al-action" v-model="filters.action" :class="inputClass()" data-testid="al-action">
                    <option value="">All</option>
                    <option v-for="action in ACTIONS" :key="action" :value="action">{{ action }}</option>
                </select>
            </div>
            <div>
                <label for="al-from" class="mb-1 block text-sm font-medium text-slate-700">From</label>
                <input id="al-from" v-model="filters.date_from" type="date" :class="inputClass()" data-testid="al-from" />
            </div>
            <div>
                <label for="al-to" class="mb-1 block text-sm font-medium text-slate-700">To</label>
                <input id="al-to" v-model="filters.date_to" type="date" :class="inputClass()" data-testid="al-to" />
            </div>
            <BaseButton type="submit" data-testid="al-apply">Filter</BaseButton>
            <BaseButton variant="secondary" data-testid="al-reset" @click="reset">Reset</BaseButton>
        </form>

        <p v-if="filterError" role="alert" class="mt-2 text-sm text-red-600" data-testid="al-filter-error">{{ filterError }}</p>

        <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <StateBox v-if="state === 'loading'" kind="loading" message="Loading audit logs…" />
            <StateBox v-else-if="state === 'error'" kind="error" :message="errorMessage" @retry="load" />
            <StateBox v-else-if="items.length === 0" kind="empty" message="No audit logs found." />

            <template v-else>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm" data-testid="audit-table">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Time</th>
                                <th scope="col" class="px-4 py-3">Action</th>
                                <th scope="col" class="px-4 py-3">By</th>
                                <th scope="col" class="px-4 py-3">Target</th>
                                <th scope="col" class="px-4 py-3">IP</th>
                                <th scope="col" class="px-4 py-3">Changes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="log in items" :key="log.id" class="align-top" :data-testid="`audit-row-${log.id}`">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ formatDateTime(log.created_at) }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-900">{{ log.action }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ log.user ? `${log.user.name} (${log.user.email})` : '—' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ log.target ? `${log.target.type} #${log.target.id}` : '—' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ log.ip_address ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <details v-if="log.old_values || log.new_values">
                                        <summary class="cursor-pointer text-indigo-600">View</summary>
                                        <!-- Hiển thị dạng text, không dùng v-html: dữ liệu log có thể chứa nội dung do người dùng nhập -->
                                        <pre v-if="log.old_values" class="mt-2 whitespace-pre-wrap rounded bg-slate-50 p-2 text-xs">Before: {{ pretty(log.old_values) }}</pre>
                                        <pre v-if="log.new_values" class="mt-2 whitespace-pre-wrap rounded bg-slate-50 p-2 text-xs">After: {{ pretty(log.new_values) }}</pre>
                                    </details>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <PaginationBar :meta="meta" @page="goToPage" @per-page="changePerPage" />
            </template>
        </div>
    </section>
</template>
