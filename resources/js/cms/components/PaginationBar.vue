<script setup>
import { computed } from 'vue';

const props = defineProps({
    // Khớp `meta` của API: { current_page, per_page, total, last_page }
    meta: { type: Object, required: true },
    perPageOptions: { type: Array, default: () => [10, 20, 50, 100] },
});

const emit = defineEmits(['page', 'per-page']);

const from = computed(() => (props.meta.total === 0 ? 0 : (props.meta.current_page - 1) * props.meta.per_page + 1));
const to = computed(() => Math.min(props.meta.current_page * props.meta.per_page, props.meta.total));

// Hiển thị tối đa 5 số trang quanh trang hiện tại
const pages = computed(() => {
    const { current_page: current, last_page: last } = props.meta;
    const start = Math.max(1, Math.min(current - 2, last - 4));
    const end = Math.min(last, start + 4);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
});

const pageButton = 'rounded-md border px-3 py-1 text-sm disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3" aria-label="Pagination">
        <p class="text-sm text-slate-600" data-testid="pagination-summary">
            Showing {{ from }}–{{ to }} of {{ meta.total }}
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                Per page
                <select
                    :value="meta.per_page"
                    class="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm"
                    data-testid="per-page-select"
                    @change="emit('per-page', Number($event.target.value))"
                >
                    <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
                </select>
            </label>

            <div class="flex items-center gap-1">
                <button
                    type="button"
                    :class="[pageButton, 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50']"
                    :disabled="meta.current_page <= 1"
                    data-testid="page-prev"
                    @click="emit('page', meta.current_page - 1)"
                >
                    Previous
                </button>

                <button
                    v-for="page in pages"
                    :key="page"
                    type="button"
                    :aria-current="page === meta.current_page ? 'page' : undefined"
                    :class="[
                        pageButton,
                        page === meta.current_page
                            ? 'border-indigo-600 bg-indigo-600 text-white'
                            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
                    ]"
                    :data-testid="`page-${page}`"
                    @click="emit('page', page)"
                >
                    {{ page }}
                </button>

                <button
                    type="button"
                    :class="[pageButton, 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50']"
                    :disabled="meta.current_page >= meta.last_page"
                    data-testid="page-next"
                    @click="emit('page', meta.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </nav>
</template>
