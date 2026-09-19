<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import StateBox from '../../components/StateBox.vue';
import UserAvatar from '../../components/UserAvatar.vue';
import RoleBadge from '../../components/RoleBadge.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import DeleteUserModal from '../../components/DeleteUserModal.vue';
import ChangeStatusModal from '../../components/ChangeStatusModal.vue';
import ResetPasswordModal from '../../components/ResetPasswordModal.vue';
import { getUser } from '../../api';
import { parseApiError } from '../../utils/errors';
import { formatDateTime } from '../../utils/format';

const route = useRoute();
const router = useRouter();

const user = ref(null);
const state = ref('loading'); // loading | ready | not-found | forbidden | error
const errorMessage = ref('');
const modal = ref(null);

const abilities = computed(() => user.value?.abilities ?? {});

async function load() {
    state.value = 'loading';

    try {
        user.value = await getUser(route.params.id);
        state.value = 'ready';
    } catch (e) {
        const parsed = parseApiError(e);

        errorMessage.value = parsed.message;
        state.value = { 404: 'not-found', 403: 'forbidden' }[parsed.status] ?? 'error';
    }
}

function onModalDone() {
    const wasDelete = modal.value?.type === 'delete';

    modal.value = null;

    // Xóa xong thì không còn gì để xem, quay về danh sách
    if (wasDelete) {
        router.push({ name: 'users' });
    } else {
        load();
    }
}

onMounted(load);
</script>

<template>
    <section class="max-w-3xl">
        <RouterLink :to="{ name: 'users' }" class="text-sm text-indigo-600 hover:underline">← Back to users</RouterLink>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">User Detail</h1>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white shadow-sm">
            <StateBox v-if="state === 'loading'" kind="loading" message="Loading user…" />

            <div v-else-if="state === 'not-found'" class="px-4 py-12 text-center" data-testid="user-not-found">
                <p class="text-sm font-medium text-slate-700">User not found.</p>
                <p class="mt-1 text-xs text-slate-500">It may have been deleted.</p>
            </div>

            <div v-else-if="state === 'forbidden'" class="px-4 py-12 text-center" data-testid="user-forbidden">
                <p class="text-sm font-medium text-slate-700">You do not have permission to view this user.</p>
            </div>

            <StateBox v-else-if="state === 'error'" kind="error" :message="errorMessage" @retry="load" />

            <div v-else class="p-6">
                <div class="flex items-center gap-4">
                    <UserAvatar :name="user.name" :src="user.avatar" size="h-16 w-16" />
                    <div>
                        <p class="text-lg font-semibold text-slate-900" data-testid="detail-name">{{ user.name }}</p>
                        <div class="mt-1 flex gap-2"><RoleBadge :role="user.role" /><StatusBadge :status="user.status" /></div>
                    </div>
                </div>

                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Email</dt><dd class="mt-0.5 break-all text-slate-900" data-testid="detail-email">{{ user.email }}</dd></div>
                    <div><dt class="text-slate-500">Phone</dt><dd class="mt-0.5 text-slate-900" data-testid="detail-phone">{{ user.phone || '—' }}</dd></div>
                    <div><dt class="text-slate-500">Created At</dt><dd class="mt-0.5 text-slate-900">{{ formatDateTime(user.created_at) }}</dd></div>
                    <div><dt class="text-slate-500">Last Login</dt><dd class="mt-0.5 text-slate-900" data-testid="detail-last-login">{{ formatDateTime(user.last_login_at) }}</dd></div>
                </dl>

                <!-- Nút hành động chỉ hiện theo abilities; API vẫn được bảo vệ độc lập -->
                <div class="mt-8 flex flex-wrap gap-3 border-t border-slate-100 pt-6">
                    <RouterLink
                        v-if="abilities.update"
                        :to="{ name: 'user-edit', params: { id: user.id } }"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        data-testid="action-edit"
                    >
                        Edit
                    </RouterLink>
                    <button v-if="abilities.reset_password" type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" data-testid="action-reset" @click="modal = { type: 'reset' }">Reset Password</button>
                    <button v-if="abilities.change_status" type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" data-testid="action-status" @click="modal = { type: 'status' }">Change Status</button>
                    <button v-if="abilities.delete" type="button" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700" data-testid="action-delete" @click="modal = { type: 'delete' }">Delete</button>
                </div>
            </div>
        </div>

        <DeleteUserModal v-if="modal?.type === 'delete'" :user="user" @close="modal = null" @done="onModalDone" />
        <ChangeStatusModal v-if="modal?.type === 'status'" :user="user" @close="modal = null" @done="onModalDone" />
        <ResetPasswordModal v-if="modal?.type === 'reset'" :user="user" @close="modal = null" @done="onModalDone" />
    </section>
</template>
