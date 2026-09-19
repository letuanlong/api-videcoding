// Cấu hình riêng cho test frontend: không nạp laravel-vite-plugin/tailwind vì test không cần chúng
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.test.js'],
        setupFiles: ['resources/js/cms/test/setup.js'],
    },
});
