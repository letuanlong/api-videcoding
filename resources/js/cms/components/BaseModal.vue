<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    // Đang gửi request: không cho đóng bằng Esc hoặc bấm nền để tránh mất phản hồi
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const panel = ref(null);

function requestClose() {
    if (!props.busy) {
        emit('close');
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        requestClose();
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown);

    // Đưa focus vào hộp thoại để người dùng bàn phím thao tác ngay
    panel.value?.querySelector('input, select, button, [tabindex]')?.focus();
});

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4" data-testid="modal-backdrop" @mousedown.self="requestClose">
        <div
            ref="panel"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl"
        >
            <h2 class="text-lg font-semibold text-slate-900">{{ title }}</h2>
            <div class="mt-3">
                <slot />
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
