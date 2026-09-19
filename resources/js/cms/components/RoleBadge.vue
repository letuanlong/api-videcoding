<script setup>
import { ROLE_LABELS } from '../utils/permissions';

defineProps({ role: { type: String, required: true } });
</script>

<template>
    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200" data-testid="role-badge">
        {{ ROLE_LABELS[role] ?? role }}
    </span>
</template>
