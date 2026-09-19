<script setup>
import { initials } from '../utils/format';

defineProps({
    name: { type: String, default: '' },
    src: { type: String, default: null },
    size: { type: String, default: 'h-10 w-10' },
});
</script>

<template>
    <img v-if="src" :src="src" :alt="`${name} avatar`" class="rounded-full object-cover" :class="size" />
    <span
        v-else
        class="inline-flex items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700"
        :class="size"
        aria-hidden="true"
        data-testid="avatar-initials"
    >
        {{ initials(name) }}
    </span>
</template>
