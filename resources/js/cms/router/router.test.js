import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import * as api from '../api';
import { createCmsRouter } from './index';
import { useAuthStore } from '../stores/auth';
import { apiError } from '../test/helpers';
import { wireHttp } from '../api/wire';
import { http } from '../api/http';

vi.mock('../api', async (importOriginal) => {
    // Giữ nguyên cleanParams thật, chỉ giả các hàm gọi mạng
    const actual = await importOriginal();
    const mocked = {};

    for (const key of Object.keys(actual)) {
        mocked[key] = typeof actual[key] === 'function' && key !== 'cleanParams' ? vi.fn() : actual[key];
    }

    return mocked;
});

let auth;
let router;

async function setup({ role = null, withUser = true } = {}) {
    setActivePinia(createPinia());
    auth = useAuthStore();

    if (role) {
        auth.setSession('tok', withUser ? { id: 1, name: 'Me', email: 'me@example.com', role } : null);

        if (!withUser) {
            auth.user = null;
        }
    }

    router = createCmsRouter(createMemoryHistory());
}

async function go(path) {
    await router.push(path);
    await router.isReady();

    return router.currentRoute.value;
}

beforeEach(() => vi.clearAllMocks());

describe('guard: chưa đăng nhập', () => {
    it('chuyển về login và nhớ trang đang muốn vào', async () => {
        await setup();

        const route = await go('/users?page=2');

        expect(route.name).toBe('login');
        expect(route.query.redirect).toBe('/users?page=2');
    });

    it.each(['/dashboard', '/profile', '/users', '/users/create', '/users/5', '/users/5/edit', '/audit-logs'])('%s yêu cầu đăng nhập', async (path) => {
        await setup();

        expect((await go(path)).name).toBe('login');
    });

    it('vào được trang login', async () => {
        await setup();

        expect((await go('/login')).name).toBe('login');
    });

    it('đường dẫn không tồn tại hiện trang 404 chứ không ép đăng nhập', async () => {
        await setup();

        expect((await go('/khong-co-trang-nay')).name).toBe('not-found');
    });
});

describe('guard: đã đăng nhập', () => {
    it('vào trang login thì chuyển về dashboard', async () => {
        await setup({ role: 'user' });

        expect((await go('/login')).name).toBe('dashboard');
    });

    it('đường dẫn gốc chuyển về dashboard', async () => {
        await setup({ role: 'user' });

        expect((await go('/')).name).toBe('dashboard');
    });

    it.each([
        ['superadmin', '/users', 'users'],
        ['superadmin', '/audit-logs', 'audit-logs'],
        ['superadmin', '/users/create', 'user-create'],
        ['admin', '/users', 'users'],
        ['admin', '/users/5', 'user-detail'],
        ['admin', '/users/5/edit', 'user-edit'],
        ['user', '/dashboard', 'dashboard'],
        ['user', '/profile', 'profile'],
    ])('%s vào được %s', async (role, path, name) => {
        await setup({ role });

        expect((await go(path)).name).toBe(name);
    });

    it.each([
        ['user', '/users'],
        ['user', '/users/create'],
        ['user', '/users/5'],
        ['user', '/users/5/edit'],
        ['user', '/audit-logs'],
        ['admin', '/audit-logs'],
    ])('%s bị chặn ở %s và chuyển sang trang 403', async (role, path) => {
        await setup({ role });

        expect((await go(path)).name).toBe('forbidden');
    });

    it('id không phải số thì là 404', async () => {
        await setup({ role: 'admin' });

        expect((await go('/users/abc')).name).toBe('not-found');
    });
});

describe('guard: F5 (còn token nhưng mất thông tin user)', () => {
    it('tải lại hồ sơ rồi mới kiểm tra quyền', async () => {
        await setup({ role: 'admin', withUser: false });
        api.getProfile.mockResolvedValue({ id: 1, name: 'Me', email: 'me@example.com', role: 'admin' });

        const route = await go('/users');

        expect(api.getProfile).toHaveBeenCalledTimes(1);
        expect(route.name).toBe('users');
        expect(auth.role).toBe('admin');
    });

    it('sau khi tải lại hồ sơ vẫn chặn nếu role không đủ quyền', async () => {
        await setup({ role: 'user', withUser: false });
        api.getProfile.mockResolvedValue({ id: 1, name: 'Me', email: 'me@example.com', role: 'user' });

        expect((await go('/audit-logs')).name).toBe('forbidden');
    });

    it('token hết hạn: xóa phiên và về login kèm thông báo', async () => {
        await setup({ role: 'admin', withUser: false });
        api.getProfile.mockRejectedValue(apiError(401, { message: 'Unauthenticated.' }));

        const route = await go('/users');

        expect(route.name).toBe('login');
        expect(route.query.notice).toBe('session_expired');
        expect(route.query.redirect).toBe('/users');
        expect(auth.isAuthenticated).toBe(false);
        expect(sessionStorage.getItem('cms.token')).toBeNull();
    });

    it('không tải lại hồ sơ nếu đã có thông tin user', async () => {
        await setup({ role: 'admin' });

        await go('/users');

        expect(api.getProfile).not.toHaveBeenCalled();
    });
});

describe('tiêu đề trang', () => {
    it('cập nhật document.title theo route', async () => {
        await setup({ role: 'admin' });

        await go('/users');

        expect(document.title).toBe('User Management · CMS');
    });
});

describe('wireHttp: xử lý 401 tập trung', () => {
    it('token hết hạn giữa chừng: xóa phiên và chuyển về login kèm trang hiện tại', async () => {
        await setup({ role: 'admin' });
        await go('/users?page=3');

        const eject = wireHttp(router, auth);
        const handler = http.interceptors.response.handlers.find(Boolean);

        await expect(handler.rejected({ response: { status: 401 }, config: { url: '/admin/users' } })).rejects.toBeTruthy();
        await router.isReady();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(auth.isAuthenticated).toBe(false);
        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query).toMatchObject({ redirect: '/users?page=3', notice: 'session_expired' });

        eject();
    });

    it('nhiều request cùng trả 401 chỉ chuyển trang một lần', async () => {
        await setup({ role: 'admin' });
        await go('/users');

        const eject = wireHttp(router, auth);
        const replace = vi.spyOn(router, 'replace');
        const handler = http.interceptors.response.handlers.find(Boolean);
        const fail = () => handler.rejected({ response: { status: 401 }, config: { url: '/x' } }).catch(() => {});

        await Promise.all([fail(), fail(), fail()]);

        expect(replace).toHaveBeenCalledTimes(1);

        eject();
    });

    it('chưa đăng nhập mà gặp 401 thì không làm gì thêm', async () => {
        await setup();
        await go('/login');

        const eject = wireHttp(router, auth);
        const replace = vi.spyOn(router, 'replace');
        const handler = http.interceptors.response.handlers.find(Boolean);

        await handler.rejected({ response: { status: 401 }, config: { url: '/profile' } }).catch(() => {});

        expect(replace).not.toHaveBeenCalled();

        eject();
    });
});
