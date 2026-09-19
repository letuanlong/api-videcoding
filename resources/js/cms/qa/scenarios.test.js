// UMS-038 / UMS-039: kịch bản QA theo vai trò, chạy trên toàn bộ ứng dụng (App + router + guard thật).
// Chỉ có tầng mạng (api) là giả; mọi thứ khác (form, store, guard, menu, modal) là mã thật.
import * as api from '../api';
import {
    NO_ABILITIES, apiError, byTestId, flush, listResponse, makeUser, mountApp,
} from '../test/helpers';

vi.mock('../api');

const PASSWORD = 'Password@123';

beforeEach(() => vi.clearAllMocks());

const ME = (role, extra = {}) => ({ id: 1, name: `${role} user`, email: `${role}@example.com`, phone: null, role, avatar: null, last_login_at: null, ...extra });

/** Đăng nhập qua giao diện thật rồi chờ tới trang đích. */
async function loginThroughUi(role, { path = '/dashboard', stats } = {}) {
    api.login.mockResolvedValue({ data: { token: `tok-${role}`, user: { id: 1, name: `${role} user`, email: `${role}@example.com`, role } } });
    api.getDashboard.mockResolvedValue({ me: ME(role), ...(stats ? { stats } : {}) });

    const app = await mountApp({ path });

    expect(app.router.currentRoute.value.name).toBe('login');

    await byTestId(app.wrapper, 'login-email').setValue(`${role}@example.com`);
    await byTestId(app.wrapper, 'login-password').setValue(PASSWORD);
    await app.wrapper.find('form').trigger('submit');

    await vi.waitFor(() => expect(byTestId(app.wrapper, 'sidebar').exists()).toBe(true));
    await flush();

    return app;
}

const menuLabels = (wrapper) => wrapper.findAll('nav[aria-label="Main"] a').map((a) => a.text());

/** Vào một đường dẫn bằng thanh địa chỉ (router.push) và chờ điều hướng xong. */
async function visit({ router, wrapper }, path) {
    await router.push(path);
    await flush();

    return wrapper;
}

// ---------------------------------------------------------------------------
// QA SCENARIO 1: SUPERADMIN
// ---------------------------------------------------------------------------
describe('QA 1 - đăng nhập SUPERADMIN', () => {
    const admin = makeUser({ id: 2, name: 'Admin Hai', email: 'admin2@example.com', role: 'admin' });
    const user = makeUser({ id: 3, name: 'User Ba', email: 'user3@example.com', role: 'user' });

    it('thấy Dashboard, User Management, Audit Logs, Profile', async () => {
        const app = await loginThroughUi('superadmin', { stats: { total: 2, active: 2, inactive: 0, blocked: 0 } });

        expect(menuLabels(app.wrapper)).toEqual(['Dashboard', 'User Management', 'Audit Logs', 'Profile']);
        expect(byTestId(app.wrapper, 'card-total').exists()).toBe(true);
        expect(byTestId(app.wrapper, 'sidebar-user-role').text()).toBe('Super Admin');
    });

    it('sau khi đăng nhập được đưa tới đúng trang đang định vào', async () => {
        api.listUsers.mockResolvedValue(listResponse([admin, user]));
        const app = await loginThroughUi('superadmin', { path: '/users' });

        await vi.waitFor(() => expect(app.router.currentRoute.value.name).toBe('users'));
    });

    it('danh sách có cả ADMIN và USER, mỗi dòng đủ hành động; tạo được ADMIN và USER', async () => {
        api.listUsers.mockResolvedValue(listResponse([admin, user]));
        api.createUser.mockResolvedValue({ message: 'User created successfully.', data: admin });
        const app = await loginThroughUi('superadmin');
        const wrapper = await visit(app, '/users');

        for (const id of [2, 3]) {
            const row = byTestId(wrapper, `user-row-${id}`);

            for (const action of ['action-view', 'action-edit', 'action-status', 'action-reset', 'action-delete']) {
                expect(row.find(`[data-testid="${action}"]`).exists(), `${id} ${action}`).toBe(true);
            }
        }

        await visit(app, '/users/create');

        expect(byTestId(wrapper, 'field-role').findAll('option').map((o) => o.text())).toEqual(['Admin', 'User']);

        // Tạo ADMIN
        await byTestId(wrapper, 'field-name').setValue('Admin Moi');
        await byTestId(wrapper, 'field-email').setValue('admin.moi@example.com');
        await byTestId(wrapper, 'field-password').setValue(PASSWORD);
        await byTestId(wrapper, 'field-password-confirmation').setValue(PASSWORD);
        await byTestId(wrapper, 'field-role').setValue('admin');
        await wrapper.find('[data-testid="user-form"]').trigger('submit');

        await vi.waitFor(() => expect(app.router.currentRoute.value.name).toBe('users'));
        expect(api.createUser).toHaveBeenCalledWith(expect.objectContaining({ role: 'admin', email: 'admin.moi@example.com' }));
    });

    it('sửa ADMIN: mở form với dữ liệu hiện tại và lưu được', async () => {
        api.getUser.mockResolvedValue(admin);
        api.updateUser.mockResolvedValue({ message: 'User updated successfully.', data: { ...admin, name: 'Admin Doi Ten' } });
        const app = await loginThroughUi('superadmin');
        const wrapper = await visit(app, '/users/2/edit');

        expect(byTestId(wrapper, 'field-name').element.value).toBe('Admin Hai');
        expect(byTestId(wrapper, 'field-role').findAll('option').map((o) => o.text())).toEqual(['Admin', 'User']);

        await byTestId(wrapper, 'field-name').setValue('Admin Doi Ten');
        await wrapper.find('[data-testid="user-form"]').trigger('submit');
        await flush();

        expect(api.updateUser).toHaveBeenCalledWith(2, expect.objectContaining({ name: 'Admin Doi Ten', role: 'admin' }));
    });

    it('xóa được cả ADMIN lẫn USER qua hộp xác nhận', async () => {
        api.listUsers.mockResolvedValue(listResponse([admin, user]));
        api.deleteUser.mockResolvedValue({ message: 'User deleted successfully.' });
        const app = await loginThroughUi('superadmin');
        const wrapper = await visit(app, '/users');

        for (const id of [2, 3]) {
            await byTestId(wrapper, `user-row-${id}`).find('[data-testid="action-delete"]').trigger('click');
            await byTestId(wrapper, 'confirm-delete').trigger('click');
            await flush();

            expect(api.deleteUser).toHaveBeenCalledWith(id);
        }
    });

    it('vào được Audit Logs', async () => {
        api.listAuditLogs.mockResolvedValue(listResponse([]));
        const app = await loginThroughUi('superadmin');

        await visit(app, '/audit-logs');

        expect(app.router.currentRoute.value.name).toBe('audit-logs');
        expect(api.listAuditLogs).toHaveBeenCalled();
    });

    it('không thao tác được trên SUPERADMIN khác (chỉ xem)', async () => {
        api.listUsers.mockResolvedValue(listResponse([makeUser({ id: 9, role: 'superadmin', abilities: NO_ABILITIES })]));
        const app = await loginThroughUi('superadmin');
        const wrapper = await visit(app, '/users?role=superadmin');
        const row = byTestId(wrapper, 'user-row-9');

        expect(row.find('[data-testid="action-view"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-delete"]').exists()).toBe(false);
        expect(row.find('[data-testid="action-edit"]').exists()).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// QA SCENARIO 2: ADMIN
// ---------------------------------------------------------------------------
describe('QA 2 - đăng nhập ADMIN', () => {
    const user = makeUser({ id: 3, name: 'User Ba', email: 'user3@example.com', role: 'user' });

    it('thấy Dashboard, User Management, Profile (không có Audit Logs)', async () => {
        const app = await loginThroughUi('admin', { stats: { total: 1, active: 1, inactive: 0, blocked: 0 } });

        expect(menuLabels(app.wrapper)).toEqual(['Dashboard', 'User Management', 'Profile']);
    });

    it('tạo, sửa, xóa, đổi trạng thái, reset mật khẩu được USER', async () => {
        api.listUsers.mockResolvedValue(listResponse([user]));
        api.createUser.mockResolvedValue({ message: 'User created successfully.', data: user });
        api.changeUserStatus.mockResolvedValue({ message: 'ok' });
        api.resetUserPassword.mockResolvedValue({ message: 'ok' });
        api.deleteUser.mockResolvedValue({ message: 'ok' });
        const app = await loginThroughUi('admin');
        const wrapper = await visit(app, '/users');
        const row = () => byTestId(wrapper, 'user-row-3');

        // Đổi trạng thái
        await row().find('[data-testid="action-status"]').trigger('click');
        await byTestId(wrapper, 'status-blocked').setValue(true);
        await byTestId(wrapper, 'confirm-status').trigger('click');
        await flush();
        expect(api.changeUserStatus).toHaveBeenCalledWith(3, 'blocked');

        // Reset mật khẩu
        await row().find('[data-testid="action-reset"]').trigger('click');
        await byTestId(wrapper, 'reset-password').setValue('NewPassword@123');
        await byTestId(wrapper, 'reset-password-confirmation').setValue('NewPassword@123');
        await wrapper.find('[role="dialog"] form').trigger('submit');
        await flush();
        expect(api.resetUserPassword).toHaveBeenCalledWith(3, expect.objectContaining({ password: 'NewPassword@123' }));

        // Xóa
        await row().find('[data-testid="action-delete"]').trigger('click');
        await byTestId(wrapper, 'confirm-delete').trigger('click');
        await flush();
        expect(api.deleteUser).toHaveBeenCalledWith(3);

        // Tạo: chỉ có role USER
        await visit(app, '/users/create');
        expect(byTestId(wrapper, 'field-role').findAll('option').map((o) => o.text())).toEqual(['User']);
    });

    it('không thể tạo ADMIN: dropdown role không có ADMIN hay SUPERADMIN', async () => {
        const app = await loginThroughUi('admin');
        const wrapper = await visit(app, '/users/create');
        const options = byTestId(wrapper, 'field-role').findAll('option').map((o) => o.element.value);

        expect(options).toEqual(['user']);
        expect(options).not.toContain('admin');
        expect(options).not.toContain('superadmin');
    });

    it('không thể sửa hay xóa ADMIN/SUPERADMIN: dòng đó không có nút hành động', async () => {
        api.listUsers.mockResolvedValue(listResponse([
            makeUser({ id: 4, role: 'admin', abilities: NO_ABILITIES }),
            makeUser({ id: 5, role: 'superadmin', abilities: NO_ABILITIES }),
        ]));
        const app = await loginThroughUi('admin');
        const wrapper = await visit(app, '/users');

        for (const id of [4, 5]) {
            const row = byTestId(wrapper, `user-row-${id}`);

            expect(row.find('[data-testid="action-edit"]').exists()).toBe(false);
            expect(row.find('[data-testid="action-delete"]').exists()).toBe(false);
            expect(row.find('[data-testid="action-status"]').exists()).toBe(false);
            expect(row.find('[data-testid="action-reset"]').exists()).toBe(false);
        }
    });

    it('vào thẳng URL sửa ADMIN thì không có form (API cũng sẽ từ chối)', async () => {
        api.getUser.mockResolvedValue(makeUser({ id: 4, role: 'admin', abilities: NO_ABILITIES }));
        const app = await loginThroughUi('admin');
        const wrapper = await visit(app, '/users/4/edit');

        expect(byTestId(wrapper, 'user-form').exists()).toBe(false);
        expect(byTestId(wrapper, 'user-forbidden').exists()).toBe(true);
    });

    it('không có quyền quản lý SUPERADMIN: API trả 403 khi xem thì hiện thông báo không có quyền', async () => {
        api.getUser.mockRejectedValue(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const app = await loginThroughUi('admin');
        const wrapper = await visit(app, '/users/1');

        expect(byTestId(wrapper, 'user-forbidden').exists()).toBe(true);
    });

    it('vào thẳng URL Audit Logs bị chặn và không gọi API', async () => {
        const app = await loginThroughUi('admin');

        await visit(app, '/audit-logs');

        expect(app.router.currentRoute.value.name).toBe('forbidden');
        expect(app.wrapper.find('[data-testid="forbidden-page"]').exists()).toBe(true);
        expect(api.listAuditLogs).not.toHaveBeenCalled();
    });
});

// ---------------------------------------------------------------------------
// QA SCENARIO 3: USER
// ---------------------------------------------------------------------------
describe('QA 3 - đăng nhập USER', () => {
    it('chỉ thấy Dashboard và Profile, dashboard cá nhân không có thống kê', async () => {
        const app = await loginThroughUi('user');

        expect(menuLabels(app.wrapper)).toEqual(['Dashboard', 'Profile']);
        expect(byTestId(app.wrapper, 'stat-cards').exists()).toBe(false);
        expect(byTestId(app.wrapper, 'me-card').exists()).toBe(true);
    });

    it.each([
        ['User Management', '/users'],
        ['tạo user', '/users/create'],
        ['xem user khác', '/users/2'],
        ['sửa user khác', '/users/2/edit'],
        ['Audit Logs', '/audit-logs'],
    ])('không vào được %s (%s) và không có lời gọi API nào', async (_name, path) => {
        const app = await loginThroughUi('user');

        await visit(app, path);

        expect(app.router.currentRoute.value.name).toBe('forbidden');
        expect(app.wrapper.find('[data-testid="forbidden-page"]').exists()).toBe(true);
        expect(api.listUsers).not.toHaveBeenCalled();
        expect(api.getUser).not.toHaveBeenCalled();
        expect(api.createUser).not.toHaveBeenCalled();
        expect(api.updateUser).not.toHaveBeenCalled();
        expect(api.deleteUser).not.toHaveBeenCalled();
        expect(api.listAuditLogs).not.toHaveBeenCalled();
    });

    it('sửa được hồ sơ của chính mình', async () => {
        api.getProfile.mockResolvedValue({ id: 1, name: 'user user', email: 'user@example.com', phone: null, role: 'user', avatar: null });
        api.updateProfile.mockResolvedValue({ message: 'Profile updated successfully.', data: { id: 1, name: 'Ten Moi', email: 'user@example.com', phone: null, role: 'user', avatar: null } });
        const app = await loginThroughUi('user');
        const wrapper = await visit(app, '/profile');

        await byTestId(wrapper, 'profile-name').setValue('Ten Moi');
        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        expect(api.updateProfile).toHaveBeenCalled();
        expect(byTestId(wrapper, 'sidebar-user-name').text()).toBe('Ten Moi');
    });
});

// ---------------------------------------------------------------------------
// XUYÊN SUỐT: F5, đăng xuất, đăng nhập lại bằng role khác
// ---------------------------------------------------------------------------
describe('QA - phiên đăng nhập', () => {
    it('F5: còn token thì tải lại hồ sơ và giữ đúng menu theo role', async () => {
        sessionStorage.setItem('cms.token', 'saved-token');
        api.getProfile.mockResolvedValue({ id: 1, name: 'Admin', email: 'a@example.com', phone: null, role: 'admin', avatar: null });
        api.getDashboard.mockResolvedValue({ me: ME('admin') });

        const app = await mountApp({ path: '/dashboard' });

        expect(api.getProfile).toHaveBeenCalledTimes(1);
        expect(menuLabels(app.wrapper)).toEqual(['Dashboard', 'User Management', 'Profile']);
    });

    it('F5 với token đã hết hạn: về trang login kèm thông báo và xóa token', async () => {
        sessionStorage.setItem('cms.token', 'expired-token');
        api.getProfile.mockRejectedValue(apiError(401, { message: 'Unauthenticated.' }));

        const app = await mountApp({ path: '/users' });

        expect(app.router.currentRoute.value.name).toBe('login');
        expect(byTestId(app.wrapper, 'login-notice').text()).toContain('session has expired');
        expect(sessionStorage.getItem('cms.token')).toBeNull();
    });

    it('đăng xuất rồi đăng nhập role khác thì menu đổi theo role mới, không sót quyền cũ', async () => {
        api.logout.mockResolvedValue({});
        api.listUsers.mockResolvedValue(listResponse([]));

        const first = await loginThroughUi('superadmin');

        expect(menuLabels(first.wrapper)).toContain('Audit Logs');

        await byTestId(first.wrapper, 'nav-logout').trigger('click');
        await vi.waitFor(() => expect(first.router.currentRoute.value.name).toBe('login'));

        expect(first.auth.isAuthenticated).toBe(false);

        // Đăng nhập lại trong cùng ứng dụng bằng USER
        api.login.mockResolvedValue({ data: { token: 'tok-user', user: { id: 2, name: 'user user', email: 'user@example.com', role: 'user' } } });
        api.getDashboard.mockResolvedValue({ me: ME('user', { id: 2 }) });

        await byTestId(first.wrapper, 'login-email').setValue('user@example.com');
        await byTestId(first.wrapper, 'login-password').setValue(PASSWORD);
        await first.wrapper.find('form').trigger('submit');
        await vi.waitFor(() => expect(byTestId(first.wrapper, 'sidebar').exists()).toBe(true));
        await flush();

        expect(menuLabels(first.wrapper)).toEqual(['Dashboard', 'Profile']);

        await visit(first, '/audit-logs');

        expect(first.router.currentRoute.value.name).toBe('forbidden');
    });

    it('sau khi đăng xuất, nút Back/URL trực tiếp không vào lại được trang riêng tư', async () => {
        api.logout.mockResolvedValue({});
        const app = await loginThroughUi('admin');

        await byTestId(app.wrapper, 'nav-logout').trigger('click');
        await vi.waitFor(() => expect(app.router.currentRoute.value.name).toBe('login'));

        await app.router.push('/users');
        await flush();

        expect(app.router.currentRoute.value.name).toBe('login');
        expect(app.router.currentRoute.value.query.redirect).toBe('/users');
    });
});
