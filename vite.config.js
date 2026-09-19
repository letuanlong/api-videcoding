import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ command }) => ({
    // Khi build dùng đường dẫn tương đối: các file JS/CSS lazy-load được tính theo vị trí file đang chạy, nên ứng dụng
    // hoạt động dù được phục vụ ở gốc domain (/build/...) hay trong thư mục con của XAMPP (/vibecoding-api/public/build/...)
    base: command === 'build' ? './' : '/',
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/cms/main.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
}));
