<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import StateBox from '../../components/StateBox.vue';
import UserForm from '../../components/UserForm.vue';
import { getUser, updateUser } from '../../api';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';
import { parseApiError } from '../../utils/errors';
import { assignableRoles } from '../../utils/permissions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToastStore();

const user = ref(null);
const state = ref('loading'); // loading | ready | not-found | forbidden | error
const errorMessage = ref('');
const submitting = ref(false);
const serverErrors = ref({});
const formError = ref('');

// ADMIN không được nâng USER lên ADMIN/SUPERADMIN nên dropdown chỉ có USER
const roleOptions = computed(() => assignableRoles(auth.role));

async function load() {
    state.value = 'loading';

    try {
        const loaded = await getUser(route.params.id);

        // Không có quyền sửa (vd. SUPERADMIN xem SUPERADMIN khác, hoặc ADMIN xem USER bị đổi quyền): chặn ngay
        if (!loaded.abilities?.update) {
            state.value = 'forbidden';

            return;
        }

        user.value = loaded;
        state.value = 'ready';
    } catch (e) {
        const parsed = parseApiError(e);

        errorMessage.value = parsed.message;
        state.value = { 404: 'not-found', 403: 'forbidden' }[parsed.status] ?? 'error';
    }
}

async function submit(payload) {
    submitting.value = true;
    serverErrors.value = {};
    formError.value = '';

    try {
        const response = await updateUser(user.value.id, payload);

        // Làm mới thông tin hiển thị bằng dữ liệu server vừa trả về
        user.value = response.data;
        toast.success(response.message || 'User updated successfully.');
    } catch (e) {
        const parsed = parseApiError(e);

        serverErrors.value = parsed.errors;
        formError.value = Object.keys(parsed.errors).length === 0 ? parsed.message : '';
    } finally {
        submitting.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="max-w-2xl">
        <RouterLink :to="{ name: 'users' }" class="text-sm text-indigo-600 hover:underline">← Back to users</RouterLink>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">Edit User</h1>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <StateBox v-if="state === 'loading'" kind="loading" message="Loading user…" />

            <p v-else-if="state === 'not-found'" class="py-8 text-center text-sm text-slate-700" data-testid="user-not-found">User not found.</p>
            <p v-else-if="state === 'forbidden'" class="py-8 text-center text-sm text-slate-700" data-testid="user-forbidden">You do not have permission to edit this user.</p>
            <StateBox v-else-if="state === 'error'" kind="error" :message="errorMessage" @retry="load" />

            <template v-else>
                <p v-if="formError" role="alert" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="form-error">{{ formError }}</p>

                <UserForm
                    mode="edit"
                    :initial="user"
                    :role-options="roleOptions"
                    :server-errors="serverErrors"
                    :submitting="submitting"
                    submit-label="Save Changes"
                    @submit="submit"
                    @cancel="router.push({ name: 'user-detail', params: { id: user.id } })"
                />
            </template>
        </div>
    </section>
</template>
