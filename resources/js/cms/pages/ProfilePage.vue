<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import BaseButton from '../components/BaseButton.vue';
import FormField from '../components/FormField.vue';
import StateBox from '../components/StateBox.vue';
import UserAvatar from '../components/UserAvatar.vue';
import RoleBadge from '../components/RoleBadge.vue';
import ChangePasswordForm from '../components/ChangePasswordForm.vue';
import { inputClass } from '../components/ui';
import { getProfile, updateProfile } from '../api';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import { parseApiError } from '../utils/errors';
import { validateAvatarFile, validateProfile } from '../utils/validators';

const auth = useAuthStore();
const toast = useToastStore();
const router = useRouter();

const profile = ref(null);
const state = ref('loading');
const errorMessage = ref('');

const form = reactive({ name: '', phone: '' });
const errors = ref({});
const formError = ref('');
const saving = ref(false);

const avatarFile = ref(null);
const avatarPreview = ref(null);
const removeAvatar = ref(false);

// Ảnh xem trước chỉ là phần phụ: nếu trình duyệt không tạo được thì vẫn cho chọn và gửi ảnh bình thường
function makePreview(file) {
    try {
        return URL.createObjectURL?.(file) ?? null;
    } catch {
        return null;
    }
}

function releasePreview() {
    if (avatarPreview.value) {
        URL.revokeObjectURL?.(avatarPreview.value);
        avatarPreview.value = null;
    }
}

function fill(data) {
    profile.value = data;
    form.name = data.name ?? '';
    form.phone = data.phone ?? '';
}

async function load() {
    state.value = 'loading';

    try {
        fill(await getProfile());
        state.value = 'ready';
    } catch (e) {
        errorMessage.value = parseApiError(e).message;
        state.value = 'error';
    }
}

function onAvatarSelected(event) {
    const file = event.target.files?.[0] ?? null;
    const problem = validateAvatarFile(file);

    releasePreview();
    delete errors.value.avatar;

    if (problem) {
        errors.value = { ...errors.value, avatar: problem };
        avatarFile.value = null;
        event.target.value = '';

        return;
    }

    avatarFile.value = file;
    removeAvatar.value = false;
    avatarPreview.value = file ? makePreview(file) : null;
}

function markRemoveAvatar() {
    releasePreview();
    avatarFile.value = null;
    removeAvatar.value = true;
}

async function save() {
    formError.value = '';
    errors.value = validateProfile(form);

    if (Object.keys(errors.value).length > 0) {
        return;
    }

    saving.value = true;

    try {
        const response = await updateProfile(
            { name: form.name.trim(), phone: form.phone.trim() },
            { avatarFile: avatarFile.value, removeAvatar: removeAvatar.value },
        );

        fill(response.data);
        auth.setUser(response.data);
        toast.success(response.message || 'Profile updated successfully.');

        releasePreview();
        avatarFile.value = null;
        removeAvatar.value = false;
    } catch (e) {
        const parsed = parseApiError(e);

        errors.value = parsed.errors;
        formError.value = Object.keys(parsed.errors).length === 0 ? parsed.message : '';
    } finally {
        saving.value = false;
    }
}

function onPasswordChanged() {
    // Backend đã thu hồi mọi token khi đổi mật khẩu nên phải đăng nhập lại
    auth.clear();
    toast.success('Password changed successfully.');
    router.replace({ name: 'login', query: { notice: 'password_changed' } });
}

const shownAvatar = () => (removeAvatar.value ? null : (avatarPreview.value ?? profile.value?.avatar ?? null));

onMounted(load);
onBeforeUnmount(releasePreview);
</script>

<template>
    <section class="max-w-2xl">
        <h1 class="text-2xl font-semibold text-slate-900">Profile</h1>

        <StateBox v-if="state === 'loading'" kind="loading" message="Loading profile…" />
        <StateBox v-else-if="state === 'error'" kind="error" :message="errorMessage" @retry="load" />

        <template v-else>
            <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <form class="space-y-5" novalidate data-testid="profile-form" @submit.prevent="save">
                    <div class="flex items-center gap-4">
                        <UserAvatar :name="form.name" :src="shownAvatar()" size="h-20 w-20" />
                        <div class="space-y-2">
                            <label for="profile-avatar" class="block text-sm font-medium text-slate-700">Avatar</label>
                            <input
                                id="profile-avatar"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                class="block text-sm text-slate-600"
                                data-testid="profile-avatar"
                                @change="onAvatarSelected"
                            />
                            <button v-if="profile.avatar && !removeAvatar" type="button" class="text-xs text-red-600 hover:underline" data-testid="remove-avatar" @click="markRemoveAvatar">Remove photo</button>
                            <p v-if="errors.avatar" role="alert" class="text-xs text-red-600" data-testid="field-error">{{ errors.avatar }}</p>
                        </div>
                    </div>

                    <FormField label="Name" for="profile-name" :error="errors.name" required>
                        <input id="profile-name" v-model="form.name" type="text" :class="inputClass(!!errors.name)" :aria-invalid="!!errors.name" data-testid="profile-name" />
                    </FormField>

                    <FormField label="Phone" for="profile-phone" :error="errors.phone">
                        <input id="profile-phone" v-model="form.phone" type="tel" :class="inputClass(!!errors.phone)" :aria-invalid="!!errors.phone" data-testid="profile-phone" />
                    </FormField>

                    <FormField label="Email" for="profile-email" hint="Email cannot be changed.">
                        <input id="profile-email" :value="profile.email" type="email" disabled :class="inputClass()" data-testid="profile-email" />
                    </FormField>

                    <div>
                        <span class="mb-1 block text-sm font-medium text-slate-700">Role</span>
                        <RoleBadge :role="profile.role" />
                    </div>

                    <p v-if="formError" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="form-error">{{ formError }}</p>

                    <div class="flex justify-end">
                        <BaseButton type="submit" :loading="saving" data-testid="profile-save">Save Changes</BaseButton>
                    </div>
                </form>
            </div>

            <div class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">Change Password</h2>
                <ChangePasswordForm @changed="onPasswordChanged" />
            </div>
        </template>
    </section>
</template>
