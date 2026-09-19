<script setup>
import { reactive, ref, watch } from 'vue';
import BaseButton from './BaseButton.vue';
import FormField from './FormField.vue';
import { inputClass } from './ui';
import { validateUserForm } from '../utils/validators';
import { ROLE_LABELS, STATUSES, STATUS_LABELS } from '../utils/permissions';

const props = defineProps({
    mode: { type: String, required: true, validator: (value) => ['create', 'edit'].includes(value) },
    initial: { type: Object, default: () => ({}) },
    // Các role được chọn; danh sách này do trang cha quyết định theo role của người đang thao tác
    roleOptions: { type: Array, required: true },
    // Lỗi từ backend dạng { field: message } (422)
    serverErrors: { type: Object, default: () => ({}) },
    submitting: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Save' },
});

const emit = defineEmits(['submit', 'cancel']);

const form = reactive({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    role: props.roleOptions[0] ?? '',
    status: 'active',
});

const errors = ref({});

function fill(values) {
    form.name = values.name ?? '';
    form.email = values.email ?? '';
    form.phone = values.phone ?? '';
    form.role = values.role ?? props.roleOptions[0] ?? '';
    form.status = values.status ?? 'active';
}

// Khi trang cha nạp xong dữ liệu (edit) hoặc cập nhật sau khi lưu thì điền lại form
watch(() => props.initial, (values) => fill(values), { immediate: true, deep: true });

watch(() => props.serverErrors, (value) => {
    errors.value = { ...value };
});

function clearField(field) {
    delete errors.value[field];
}

function submit() {
    errors.value = validateUserForm(form, props.mode);

    if (Object.keys(errors.value).length > 0) {
        return;
    }

    const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim(),
        role: form.role,
        status: form.status,
    };

    if (props.mode === 'create') {
        payload.password = form.password;
        payload.password_confirmation = form.password_confirmation;
    }

    // Giữ nguyên dữ liệu đã nhập: nếu server báo lỗi (vd. email trùng) người dùng chỉ cần sửa đúng ô đó.
    // Khi thành công trang cha điều hướng đi nên form tự biến mất cùng dữ liệu.
    emit('submit', payload);
}
</script>

<template>
    <form class="space-y-5" novalidate data-testid="user-form" @submit.prevent="submit">
        <FormField label="Name" for="user-name" :error="errors.name" required>
            <input id="user-name" v-model="form.name" type="text" autocomplete="off" :class="inputClass(!!errors.name)" :aria-invalid="!!errors.name" data-testid="field-name" @input="clearField('name')" />
        </FormField>

        <FormField label="Email" for="user-email" :error="errors.email" required>
            <input id="user-email" v-model="form.email" type="email" autocomplete="off" :class="inputClass(!!errors.email)" :aria-invalid="!!errors.email" data-testid="field-email" @input="clearField('email')" />
        </FormField>

        <FormField label="Phone" for="user-phone" :error="errors.phone">
            <input id="user-phone" v-model="form.phone" type="tel" autocomplete="off" :class="inputClass(!!errors.phone)" :aria-invalid="!!errors.phone" data-testid="field-phone" @input="clearField('phone')" />
        </FormField>

        <template v-if="mode === 'create'">
            <FormField label="Password" for="user-password" :error="errors.password" hint="8–72 characters with upper and lower case letters, a number and a symbol." required>
                <input id="user-password" v-model="form.password" type="password" autocomplete="new-password" :class="inputClass(!!errors.password)" :aria-invalid="!!errors.password" data-testid="field-password" @input="clearField('password')" />
            </FormField>

            <FormField label="Confirm Password" for="user-password-confirmation" :error="errors.password_confirmation" required>
                <input id="user-password-confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="inputClass(!!errors.password_confirmation)" :aria-invalid="!!errors.password_confirmation" data-testid="field-password-confirmation" @input="clearField('password_confirmation')" />
            </FormField>
        </template>

        <div class="grid gap-5 sm:grid-cols-2">
            <FormField label="Role" for="user-role" :error="errors.role" required>
                <select id="user-role" v-model="form.role" :class="inputClass(!!errors.role)" data-testid="field-role" @change="clearField('role')">
                    <option v-for="role in roleOptions" :key="role" :value="role">{{ ROLE_LABELS[role] ?? role }}</option>
                </select>
            </FormField>

            <FormField label="Status" for="user-status" :error="errors.status" required>
                <select id="user-status" v-model="form.status" :class="inputClass(!!errors.status)" data-testid="field-status" @change="clearField('status')">
                    <option v-for="status in STATUSES" :key="status" :value="status">{{ STATUS_LABELS[status] }}</option>
                </select>
            </FormField>
        </div>

        <div class="flex justify-end gap-3 pt-2">
            <BaseButton variant="secondary" :disabled="submitting" data-testid="form-cancel" @click="emit('cancel')">Cancel</BaseButton>
            <BaseButton type="submit" :loading="submitting" data-testid="form-submit">{{ submitLabel }}</BaseButton>
        </div>
    </form>
</template>
