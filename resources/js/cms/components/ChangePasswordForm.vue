<script setup>
import { reactive, ref } from 'vue';
import BaseButton from './BaseButton.vue';
import FormField from './FormField.vue';
import { inputClass } from './ui';
import { changePassword } from '../api';
import { parseApiError } from '../utils/errors';
import { validatePassword, validatePasswordConfirmation } from '../utils/validators';

const emit = defineEmits(['changed']);

const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const errors = ref({});
const error = ref('');
const loading = ref(false);

function clearField(field) {
    delete errors.value[field];
}

function validate() {
    const found = {
        current_password: form.current_password ? null : 'Current password is required.',
        password: validatePassword(form.password),
        password_confirmation: validatePasswordConfirmation(form.password, form.password_confirmation),
    };

    if (form.current_password && form.password && form.current_password === form.password) {
        found.password = 'The new password must be different from the current password.';
    }

    return Object.fromEntries(Object.entries(found).filter(([, message]) => message));
}

async function submit() {
    error.value = '';
    errors.value = validate();

    if (Object.keys(errors.value).length > 0) {
        return;
    }

    loading.value = true;

    try {
        await changePassword({ ...form });

        emit('changed');
    } catch (e) {
        const parsed = parseApiError(e);

        errors.value = parsed.errors;
        error.value = Object.keys(parsed.errors).length === 0 ? parsed.message : '';

        // Mật khẩu hiện tại sai thì xóa ô đó để nhập lại; mật khẩu mới giữ nguyên để khỏi gõ lại
        if (parsed.errors.current_password) {
            form.current_password = '';
        }
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" novalidate data-testid="change-password-form" @submit.prevent="submit">
        <FormField label="Current Password" for="current-password" :error="errors.current_password" required>
            <input id="current-password" v-model="form.current_password" type="password" autocomplete="current-password" :class="inputClass(!!errors.current_password)" :aria-invalid="!!errors.current_password" data-testid="cp-current" @input="clearField('current_password')" />
        </FormField>

        <FormField label="New Password" for="new-password" :error="errors.password" hint="8–72 characters with upper and lower case letters, a number and a symbol." required>
            <input id="new-password" v-model="form.password" type="password" autocomplete="new-password" :class="inputClass(!!errors.password)" :aria-invalid="!!errors.password" data-testid="cp-new" @input="clearField('password')" />
        </FormField>

        <FormField label="Confirm New Password" for="new-password-confirmation" :error="errors.password_confirmation" required>
            <input id="new-password-confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="inputClass(!!errors.password_confirmation)" :aria-invalid="!!errors.password_confirmation" data-testid="cp-confirm" @input="clearField('password_confirmation')" />
        </FormField>

        <p v-if="error" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="form-error">{{ error }}</p>

        <div class="flex justify-end">
            <BaseButton type="submit" :loading="loading" data-testid="cp-submit">Change Password</BaseButton>
        </div>
    </form>
</template>
