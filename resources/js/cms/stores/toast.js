import { ref } from 'vue';
import { defineStore } from 'pinia';

const DEFAULT_DURATION = 5000;

export const useToastStore = defineStore('toast', () => {
    const toasts = ref([]);
    let nextId = 1;

    function dismiss(id) {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    function push(type, message, duration = DEFAULT_DURATION) {
        const id = nextId++;

        toasts.value.push({ id, type, message });

        if (duration > 0) {
            setTimeout(() => dismiss(id), duration);
        }

        return id;
    }

    return {
        toasts,
        dismiss,
        success: (message, duration) => push('success', message, duration),
        error: (message, duration) => push('error', message, duration),
    };
});
