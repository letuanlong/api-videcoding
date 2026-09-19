<script setup>
// Khung dùng chung cho 3 trạng thái của một vùng dữ liệu: đang tải, rỗng, lỗi.
defineProps({
    kind: { type: String, required: true, validator: (value) => ['loading', 'empty', 'error'].includes(value) },
    message: { type: String, default: '' },
});

defineEmits(['retry']);
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-3 px-4 py-12 text-center" :data-testid="`state-${kind}`">
        <template v-if="kind === 'loading'">
            <svg class="h-6 w-6 animate-spin text-indigo-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
                <path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75" />
            </svg>
            <p class="text-sm text-slate-500" role="status">{{ message || 'Loading…' }}</p>
        </template>

        <template v-else-if="kind === 'empty'">
            <p class="text-sm font-medium text-slate-700">{{ message || 'Nothing to show.' }}</p>
            <slot />
        </template>

        <template v-else>
            <p class="text-sm text-red-600" role="alert">{{ message || 'Something went wrong.' }}</p>
            <button
                type="button"
                class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                data-testid="retry-button"
                @click="$emit('retry')"
            >
                Try again
            </button>
        </template>
    </div>
</template>
