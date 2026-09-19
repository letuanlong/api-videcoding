<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import BaseButton from '../../components/BaseButton.vue';
import StateBox from '../../components/StateBox.vue';
import PaginationBar from '../../components/PaginationBar.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import RoleBadge from '../../components/RoleBadge.vue';
import DeleteUserModal from '../../components/DeleteUserModal.vue';
import ChangeStatusModal from '../../components/ChangeStatusModal.vue';
import ResetPasswordModal from '../../components/ResetPasswordModal.vue';
import { inputClass } from '../../components/ui';
import { listUsers } from '../../api';
import { useAuthStore } from '../../stores/auth';
import { parseApiError } from '../../utils/errors';
import { formatDateTime } from '../../utils/format';
import { filterableRoles, ROLE_LABELS, STATUS_LABELS, STATUSES } from '../../utils/permissions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const items = ref([]);
const meta = ref(null);
const state = ref('loading'); // loading | ready | error
const errorMessage = ref('');
const modal = ref(null); // { type: 'delete' | 'status' | 'reset', user }

// Ô nhập của bộ lọc; chỉ áp dụng khi bấm Search/đổi dropdown. URL (route.query) mới là nguồn sự thật của truy vấn.
const draft = reactive({ search: '', role: 'all', status: 'all' });

const roleOptions = computed(() => filterableRoles(auth.role));

// Chống race condition: chỉ nhận phản hồi của request mới nhất
let requestSeq = 0;

function queryFromRoute() {
    const q = route.query;
    const page = Number(q.page);

    return {
        search: typeof q.search === 'string' ? q.search : '',
        role: typeof q.role === 'string' ? q.role : 'all',
        status: typeof q.status === 'string' ? q.status : 'all',
        page: Number.isInteger(page) && page > 0 ? page : 1,
        per_page: q.per_page ? Number(q.per_page) : undefined,
    };
}

const hasActiveFilters = computed(() => {
    const q = queryFromRoute();

    return q.search !== '' || q.role !== 'all' || q.status !== 'all';
});

/** Query gọn cho URL: bỏ giá trị mặc định. */
function compactQuery(values) {
    const query = {};

    if (values.search) query.search = values.search;
    if (values.role && values.role !== 'all') query.role = values.role;
    if (values.status && values.status !== 'all') query.status = values.status;
    if (values.page > 1) query.page = String(values.page);
    if (values.per_page) query.per_page = String(values.per_page);

    return query;
}

function sameQuery(a, b) {
    return JSON.stringify(Object.entries(a).sort()) === JSON.stringify(Object.entries(b).sort());
}

function navigate(changes) {
    const query = compactQuery({ ...queryFromRoute(), ...changes });

    if (sameQuery(query, route.query)) {
        // Bấm Search với bộ lọc không đổi: hiểu là muốn tải lại
        load();

        return;
    }

    router.push({ name: 'users', query });
}

const applyFilters = () => navigate({ search: draft.search.trim(), role: draft.role, status: draft.status, page: 1 });
const clearFilters = () => navigate({ search: '', role: 'all', status: 'all', page: 1 });
const goToPage = (page) => navigate({ page });
const changePerPage = (perPage) => navigate({ per_page: perPage, page: 1 });

async function load() {
    const seq = ++requestSeq;

    state.value = 'loading';
    errorMessage.value = '';

    try {
        const response = await listUsers(queryFromRoute());

        if (seq !== requestSeq) return;

        items.value = response.data;
        meta.value = response.meta;
        state.value = 'ready';

        // Đứng ở trang không còn dữ liệu (vd. vừa xóa hết bản ghi của trang cuối) thì lùi về trang cuối
        if (response.data.length === 0 && response.meta.current_page > 1) {
            navigate({ page: response.meta.last_page });
        }
    } catch (e) {
        if (seq !== requestSeq) return;

        errorMessage.value = parseApiError(e).message;
        state.value = 'error';
    }
}

watch(
    () => route.query,
    () => {
        if (route.name !== 'users') return;

        const q = queryFromRoute();

        draft.search = q.search;
        draft.role = q.role;
        draft.status = q.status;

        load();
    },
    { immediate: true },
);

function open(type, user) {
    modal.value = { type, user };
}

function onModalDone() {
    modal.value = null;
    load();
}
</script>

<template>
    <section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold text-slate-900">User Management</h1>

            <RouterLink
                :to="{ name: 'user-create' }"
                class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                data-testid="create-user"
            >
                + Create User
            </RouterLink>
        </div>

        <form class="mt-6 flex flex-wrap items-end gap-3" role="search" @submit.prevent="applyFilters">
            <div class="min-w-[14rem] flex-1">
                <label for="filter-search" class="mb-1 block text-sm font-medium text-slate-700">Search</label>
                <input id="filter-search" v-model="draft.search" type="search" placeholder="Name, email or phone" maxlength="100" :class="inputClass()" data-testid="filter-search" />
            </div>

            <div>
                <label for="filter-role" class="mb-1 block text-sm font-medium text-slate-700">Role</label>
                <select id="filter-role" v-model="draft.role" :class="inputClass()" data-testid="filter-role">
                    <option value="all">All</option>
                    <option v-for="role in roleOptions" :key="role" :value="role">{{ ROLE_LABELS[role] }}</option>
                </select>
            </div>

            <div>
                <label for="filter-status" class="mb-1 block text-sm font-medium text-slate-700">Status</label>
                <select id="filter-status" v-model="draft.status" :class="inputClass()" data-testid="filter-status">
                    <option value="all">All</option>
                    <option v-for="status in STATUSES" :key="status" :value="status">{{ STATUS_LABELS[status] }}</option>
                </select>
            </div>

            <BaseButton type="submit" data-testid="filter-submit">Search</BaseButton>
        </form>

        <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <StateBox v-if="state === 'loading'" kind="loading" message="Loading users…" />
            <StateBox v-else-if="state === 'error'" kind="error" :message="errorMessage" @retry="load" />

            <StateBox v-else-if="items.length === 0" kind="empty" message="No users found.">
                <BaseButton v-if="hasActiveFilters" variant="secondary" data-testid="clear-filters" @click="clearFilters">Clear filters</BaseButton>
            </StateBox>

            <template v-else>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm" data-testid="users-table">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Name</th>
                                <th scope="col" class="px-4 py-3">Email</th>
                                <th scope="col" class="px-4 py-3">Role</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Created At</th>
                                <th scope="col" class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="user in items" :key="user.id" :data-testid="`user-row-${user.id}`">
                                <td class="px-4 py-3 font-medium text-slate-900">{{ user.name }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ user.email }}</td>
                                <td class="px-4 py-3"><RoleBadge :role="user.role" /></td>
                                <td class="px-4 py-3"><StatusBadge :status="user.status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ formatDateTime(user.created_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <RouterLink :to="{ name: 'user-detail', params: { id: user.id } }" class="text-indigo-600 hover:underline" data-testid="action-view">View</RouterLink>

                                        <!-- Nút chỉ hiện khi API cho phép (abilities); backend vẫn kiểm tra lại khi gọi -->
                                        <RouterLink v-if="user.abilities?.update" :to="{ name: 'user-edit', params: { id: user.id } }" class="text-indigo-600 hover:underline" data-testid="action-edit">Edit</RouterLink>
                                        <button v-if="user.abilities?.change_status" type="button" class="text-indigo-600 hover:underline" data-testid="action-status" @click="open('status', user)">Change Status</button>
                                        <button v-if="user.abilities?.reset_password" type="button" class="text-indigo-600 hover:underline" data-testid="action-reset" @click="open('reset', user)">Reset Password</button>
                                        <button v-if="user.abilities?.delete" type="button" class="text-red-600 hover:underline" data-testid="action-delete" @click="open('delete', user)">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <PaginationBar :meta="meta" @page="goToPage" @per-page="changePerPage" />
            </template>
        </div>

        <DeleteUserModal v-if="modal?.type === 'delete'" :user="modal.user" @close="modal = null" @done="onModalDone" />
        <ChangeStatusModal v-if="modal?.type === 'status'" :user="modal.user" @close="modal = null" @done="onModalDone" />
        <ResetPasswordModal v-if="modal?.type === 'reset'" :user="modal.user" @close="modal = null" @done="onModalDone" />
    </section>
</template>
