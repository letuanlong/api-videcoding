import '../../css/app.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createWebHistory } from 'vue-router';
import App from './App.vue';
import { createCmsRouter } from './router';
import { http } from './api/http';
import { wireHttp } from './api/wire';
import { useAuthStore } from './stores/auth';
import { readBasePaths } from './utils/basePaths';

// Đường dẫn gốc do Laravel cung cấp: /cms và /api ở gốc domain, hoặc kèm thư mục con khi chạy trong XAMPP
const base = readBasePaths();

http.defaults.baseURL = base.api;

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);

const router = createCmsRouter(createWebHistory(`${base.cms}/`));

wireHttp(router, useAuthStore());

app.use(router);
app.mount('#app');
