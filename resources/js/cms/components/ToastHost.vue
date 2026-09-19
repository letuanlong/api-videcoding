<script setup>
import { useToastStore } from '../stores/toast';

const toast = useToastStore();
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4" aria-live="polite">
        <div
            v-for="item in toast.toasts"
            :key="item.id"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg px-4 py-3 text-sm shadow-lg"
            :class="item.type === 'error' ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white'"
            :data-testid="`toast-${item.type}`"
            role="status"
        >
            <p class="flex-1">{{ item.message }}</p>
            <button type="button" class="opacity-80 hover:opacity-100" aria-label="Dismiss" @click="toast.dismiss(item.id)">✕</button>
        </div>
    </div>
</template>
