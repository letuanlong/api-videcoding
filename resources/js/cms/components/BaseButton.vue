<script setup>
defineProps({
    variant: { type: String, default: 'primary' },
    type: { type: String, default: 'button' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const variants = {
    primary: 'bg-indigo-600 text-white hover:bg-indigo-700 focus-visible:ring-indigo-300',
    secondary: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:ring-slate-300',
    danger: 'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-300',
    ghost: 'text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-300',
};
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading"
        class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-60"
        :class="variants[variant] ?? variants.primary"
    >
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true" data-testid="button-spinner">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
            <path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75" />
        </svg>
        <slot />
    </button>
</template>
