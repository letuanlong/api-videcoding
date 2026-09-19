<script setup>
import { computed, ref } from 'vue';
import BaseModal from './BaseModal.vue';
import BaseButton from './BaseButton.vue';
import StatusBadge from './StatusBadge.vue';
import { changeUserStatus } from '../api';
import { parseApiError } from '../utils/errors';
import { statusConfirmation } from '../utils/format';
import { STATUSES, STATUS_LABELS } from '../utils/permissions';
import { useToastStore } from '../stores/toast';

const props = defineProps({ user: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const toast = useToastStore();
const selected = ref(props.user.status);
const loading = ref(false);
const error = ref('');

const unchanged = computed(() => selected.value === props.user.status);

async function confirm() {
    loading.value = true;
    error.value = '';

    try {
        const response = await changeUserStatus(props.user.id, selected.value);

        toast.success(response.message || 'User status updated successfully.');
        emit('done');
    } catch (e) {
        error.value = parseApiError(e).message;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <BaseModal title="Change status" :busy="loading" @close="emit('close')">
        <p class="text-sm text-slate-600">
            {{ user.name }} is currently
            <StatusBadge :status="user.status" />
        </p>

        <fieldset class="mt-4 space-y-2">
            <legend class="sr-only">New status</legend>
            <label v-for="status in STATUSES" :key="status" class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="selected" type="radio" name="status" :value="status" :data-testid="`status-${status}`" :disabled="loading" />
                {{ STATUS_LABELS[status] }}
            </label>
        </fieldset>

        <p v-if="!unchanged" class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800" data-testid="status-confirmation">
            {{ statusConfirmation(selected) }}
        </p>

        <p v-if="error" role="alert" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="modal-error">{{ error }}</p>

        <template #actions>
            <BaseButton variant="secondary" :disabled="loading" data-testid="cancel-button" @click="emit('close')">Cancel</BaseButton>
            <BaseButton :loading="loading" :disabled="unchanged" data-testid="confirm-status" @click="confirm">Confirm</BaseButton>
        </template>
    </BaseModal>
</template>
