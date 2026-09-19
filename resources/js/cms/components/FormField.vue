<script setup>
defineProps({
    label: { type: String, required: true },
    for: { type: String, required: true },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    required: { type: Boolean, default: false },
});
</script>

<template>
    <div>
        <label :for="$props.for" class="mb-1 block text-sm font-medium text-slate-700">
            {{ label }}
            <span v-if="required" class="text-red-500" aria-hidden="true">*</span>
        </label>
        <slot />
        <p v-if="hint && !error" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
        <p v-if="error" :id="`${$props.for}-error`" role="alert" class="mt-1 text-xs text-red-600" data-testid="field-error">{{ error }}</p>
    </div>
</template>
