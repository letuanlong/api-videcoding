<script setup>
import { computed, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import UserForm from '../../components/UserForm.vue';
import { createUser } from '../../api';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';
import { parseApiError } from '../../utils/errors';
import { assignableRoles } from '../../utils/permissions';

const auth = useAuthStore();
const toast = useToastStore();
const router = useRouter();

// ADMIN chỉ tạo được USER; SUPERADMIN tạo được ADMIN và USER
const roleOptions = computed(() => assignableRoles(auth.role));

const submitting = ref(false);
const serverErrors = ref({});
const formError = ref('');

async function submit(payload) {
    submitting.value = true;
    serverErrors.value = {};
    formError.value = '';

    try {
        const response = await createUser(payload);

        toast.success(response.message || 'User created successfully.');
        await router.push({ name: 'users' });
    } catch (e) {
        const parsed = parseApiError(e);

        // 422 (vd. email trùng) hiện ngay dưới từng ô; các lỗi khác hiện ở đầu form
        serverErrors.value = parsed.errors;
        formError.value = Object.keys(parsed.errors).length === 0 ? parsed.message : '';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <section class="max-w-2xl">
        <RouterLink :to="{ name: 'users' }" class="text-sm text-indigo-600 hover:underline">← Back to users</RouterLink>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">Create User</h1>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p v-if="formError" role="alert" class="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="form-error">{{ formError }}</p>

            <UserForm
                mode="create"
                :role-options="roleOptions"
                :server-errors="serverErrors"
                :submitting="submitting"
                submit-label="Create User"
                @submit="submit"
                @cancel="router.push({ name: 'users' })"
            />
        </div>
    </section>
</template>
