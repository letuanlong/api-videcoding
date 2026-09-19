import { mount } from '@vue/test-utils';
import { createMemoryHistory, createWebHistory } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { createCmsRouter } from '../router';
import { readBasePaths } from './basePaths';

// Router chỉ điều hướng lần đầu khi được cài vào một app, giống main.js
const install = async (router) => {
    mount({ template: '<div />' }, { global: { plugins: [router] } });
    await router.isReady();
};

const rootWith = (attributes) => {
    const el = document.createElement('div');

    el.id = 'app';
    Object.entries(attributes).forEach(([key, value]) => el.setAttribute(key, value));

    return el;
};

describe('readBasePaths', () => {
    it('không có thuộc tính thì dùng mặc định /cms và /api', () => {
        expect(readBasePaths(rootWith({}))).toEqual({ cms: '/cms', api: '/api' });
        expect(readBasePaths(null)).toEqual({ cms: '/cms', api: '/api' });
    });

    it('đọc đường dẫn kèm thư mục con của XAMPP', () => {
        const root = rootWith({ 'data-cms-base': '/vibecoding-api/public/cms', 'data-api-base': '/vibecoding-api/public/api' });

        expect(readBasePaths(root)).toEqual({ cms: '/vibecoding-api/public/cms', api: '/vibecoding-api/public/api' });
    });

    it('bỏ dấu / ở cuối', () => {
        expect(readBasePaths(rootWith({ 'data-cms-base': '/x/cms/', 'data-api-base': '/x/api///' }))).toEqual({ cms: '/x/cms', api: '/x/api' });
    });

    it.each([
        'https://evil.com/cms',
        '//evil.com/cms',
        'http://evil.com',
        'cms',
        'javascript:alert(1)',
        '/a b',
        '/<script>',
        '/a\\b',
        '',
    ])('từ chối giá trị không phải đường dẫn nội bộ: %j (dùng mặc định, không thể trỏ sang origin khác)', (value) => {
        expect(readBasePaths(rootWith({ 'data-cms-base': value, 'data-api-base': value }))).toEqual({ cms: '/cms', api: '/api' });
    });

    it('chỉ "/" thì quay về mặc định', () => {
        expect(readBasePaths(rootWith({ 'data-cms-base': '/', 'data-api-base': '/' }))).toEqual({ cms: '/cms', api: '/api' });
    });
});

describe('router dưới thư mục con', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('link được sinh kèm tiền tố thư mục con', async () => {
        const router = createCmsRouter(createMemoryHistory('/vibecoding-api/public/cms/'));

        expect(router.resolve({ name: 'users' }).href).toBe('/vibecoding-api/public/cms/users');
        expect(router.resolve({ name: 'user-edit', params: { id: 7 } }).href).toBe('/vibecoding-api/public/cms/users/7/edit');
        expect(router.resolve({ name: 'login' }).href).toBe('/vibecoding-api/public/cms/login');
    });

    it('nhận đúng route từ URL thật của trình duyệt có tiền tố thư mục con', async () => {
        window.history.pushState({}, '', '/vibecoding-api/public/cms/login');

        const router = createCmsRouter(createWebHistory('/vibecoding-api/public/cms/'));

        await install(router);

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.path).toBe('/login');
    });

    it('URL ngoài tiền tố thư mục con không bị coi là trang CMS hợp lệ', async () => {
        window.history.pushState({}, '', '/cms/login');

        const router = createCmsRouter(createWebHistory('/vibecoding-api/public/cms/'));

        await install(router);

        expect(router.currentRoute.value.name).not.toBe('login');
    });
});
