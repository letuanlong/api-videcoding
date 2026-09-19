<script setup>
import { ref } from 'vue';
import BaseModal from './BaseModal.vue';
import BaseButton from './BaseButton.vue';
import { deleteUser } from '../api';
import { parseApiError } from '../utils/errors';
import { useToastStore } from '../stores/toast';

const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const toast = useToastStore();
const loading = ref(false);
const error = ref('');

async function confirm() {
    loading.value = true;
    error.value = '';

    try {
        const response = await deleteUser(props.user.id);

        toast.success(response.message || 'User deleted successfully.');
        emit('done');
    } catch (e) {
        error.value = parseApiError(e).message;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <BaseModal title="Are you sure?" :busy="loading" @close="emit('close')">
        <p class="text-sm text-slate-600">You are about to delete:</p>
        <p class="mt-1 text-sm font-semibold text-slate-900" data-testid="delete-target">"{{ user.name }}"</p>
        <p class="mt-2 text-xs text-slate-500">The user will no longer be able to log in and will disappear from the list.</p>

        <p v-if="error" role="alert" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="modal-error">{{ error }}</p>

        <template #actions>
            <BaseButton variant="secondary" :disabled="loading" data-testid="cancel-button" @click="emit('close')">Cancel</BaseButton>
            <BaseButton variant="danger" :loading="loading" data-testid="confirm-delete" @click="confirm">Delete</BaseButton>
        </template>
    </BaseModal>
</template>
