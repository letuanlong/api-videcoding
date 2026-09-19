<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import FormField from '../components/FormField.vue';
import { inputClass } from '../components/ui';
import { useAuthStore } from '../stores/auth';
import { parseApiError } from '../utils/errors';
import { safeRedirect } from '../utils/format';
import { validateLogin } from '../utils/validators';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const form = reactive({ email: '', password: '' });
const errors = ref({});
const formError = ref('');
const loading = ref(false);

const NOTICES = {
    session_expired: 'Your session has expired. Please log in again.',
    password_changed: 'Your password was changed. Please log in with the new password.',
};

const notice = computed(() => NOTICES[route.query.notice] ?? '');

function clearField(field) {
    delete errors.value[field];
    formError.value = '';
}

async function submit() {
    formError.value = '';
    errors.value = validateLogin(form);

    if (Object.keys(errors.value).length > 0) {
        return;
    }

    loading.value = true;

    try {
        await auth.login({ email: form.email.trim(), password: form.password });

        // safeRedirect chỉ nhận đường dẫn nội bộ, chống open redirect
        await router.replace(safeRedirect(route.query.redirect));
    } catch (e) {
        const parsed = parseApiError(e);

        if (Object.keys(parsed.errors).length > 0) {
            errors.value = parsed.errors;
        } else if (parsed.status === 401) {
            // Không nói rõ sai email hay sai mật khẩu
            formError.value = 'Invalid email or password.';
        } else {
            // 403 (tài khoản inactive/blocked), 429, lỗi mạng: dùng nguyên thông báo đã chuẩn hóa
            formError.value = parsed.message;
        }

        form.password = '';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <h1 class="mb-6 text-center text-2xl font-semibold tracking-tight text-slate-900">CMS LOGIN</h1>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <p v-if="notice" class="mb-4 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-800" role="status" data-testid="login-notice">{{ notice }}</p>

                <form class="space-y-4" novalidate @submit.prevent="submit">
                    <FormField label="Email" for="login-email" :error="errors.email">
                        <input
                            id="login-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            autofocus
                            :class="inputClass(!!errors.email)"
                            :aria-invalid="!!errors.email"
                            data-testid="login-email"
                            @input="clearField('email')"
                        />
                    </FormField>

                    <FormField label="Password" for="login-password" :error="errors.password">
                        <input
                            id="login-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            :class="inputClass(!!errors.password)"
                            :aria-invalid="!!errors.password"
                            data-testid="login-password"
                            @input="clearField('password')"
                        />
                    </FormField>

                    <p v-if="formError" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="login-error">{{ formError }}</p>

                    <BaseButton type="submit" class="w-full" :loading="loading" data-testid="login-submit">
                        {{ loading ? 'Signing in…' : 'LOGIN' }}
                    </BaseButton>
                </form>
            </div>
        </div>
    </div>
</template>
