<script setup>
import { computed } from 'vue';
import { STATUS_LABELS } from '../utils/permissions';

const props = defineProps({ status: { type: String, required: true } });

const styles = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    inactive: 'bg-amber-50 text-amber-700 ring-amber-200',
    blocked: 'bg-red-50 text-red-700 ring-red-200',
};

const classes = computed(() => styles[props.status] ?? 'bg-slate-100 text-slate-600 ring-slate-200');
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="classes" data-testid="status-badge">
        {{ STATUS_LABELS[status] ?? status }}
    </span>
</template>
