<script setup>
import { reactive, ref } from 'vue';
import BaseModal from './BaseModal.vue';
import BaseButton from './BaseButton.vue';
import FormField from './FormField.vue';
import { inputClass } from './ui';
import { resetUserPassword } from '../api';
import { parseApiError } from '../utils/errors';
import { validatePasswordPair } from '../utils/validators';
import { useToastStore } from '../stores/toast';

const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const toast = useToastStore();
const form = reactive({ password: '', password_confirmation: '' });
const errors = ref({});
const error = ref('');
const loading = ref(false);

function clearField(field) {
    delete errors.value[field];
}

async function submit() {
    error.value = '';
    errors.value = validatePasswordPair(form);

    if (Object.keys(errors.value).length > 0) {
        return;
    }

    loading.value = true;

    try {
        const response = await resetUserPassword(props.user.id, { ...form });

        toast.success(response.message || 'Password reset successfully.');
        emit('done');
    } catch (e) {
        const parsed = parseApiError(e);

        errors.value = parsed.errors;
        // Lỗi không gắn với trường nào (403, 404, 500...) thì hiển thị chung
        error.value = Object.keys(parsed.errors).length === 0 ? parsed.message : '';
    } finally {
        // Thành công thì hộp thoại đóng (dữ liệu biến mất cùng component); thất bại thì giữ để không phải gõ lại
        loading.value = false;
    }
}
</script>

<template>
    <BaseModal title="Reset password" :busy="loading" @close="emit('close')">
        <p class="mb-4 text-sm text-slate-600">Set a new password for <strong>{{ user.name }}</strong>. They will be signed out of all devices.</p>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField label="New Password" for="reset-password" :error="errors.password" required>
                <input
                    id="reset-password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    :class="inputClass(!!errors.password)"
                    :aria-invalid="!!errors.password"
                    data-testid="reset-password"
                    @input="clearField('password')"
                />
            </FormField>

            <FormField label="Confirm Password" for="reset-password-confirmation" :error="errors.password_confirmation" required>
                <input
                    id="reset-password-confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    :class="inputClass(!!errors.password_confirmation)"
                    :aria-invalid="!!errors.password_confirmation"
                    data-testid="reset-password-confirmation"
                    @input="clearField('password_confirmation')"
                />
            </FormField>

            <p v-if="error" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="modal-error">{{ error }}</p>

            <div class="flex justify-end gap-3 pt-2">
                <BaseButton variant="secondary" :disabled="loading" data-testid="cancel-button" @click="emit('close')">Cancel</BaseButton>
                <BaseButton type="submit" :loading="loading" data-testid="confirm-reset">Reset Password</BaseButton>
            </div>
        </form>
    </BaseModal>
</template>
