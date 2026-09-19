import * as api from '../api';
import LoginPage from './LoginPage.vue';
import DashboardPage from './DashboardPage.vue';
import { apiError, byTestId, deferred, flush, mountApp, mountAt, networkError, validationError } from '../test/helpers';

vi.mock('../api');

beforeEach(() => vi.clearAllMocks());

// ---------------------------------------------------------------------------
// UMS-024: LOGIN PAGE
// ---------------------------------------------------------------------------
describe('LoginPage (UMS-024)', () => {
    const mountLogin = (path = '/login') => mountAt(LoginPage, { path, authenticated: false });

    async function submit(wrapper, email = 'user@example.com', password = 'Password@123') {
        await byTestId(wrapper, 'login-email').setValue(email);
        await byTestId(wrapper, 'login-password').setValue(password);
        await wrapper.find('form').trigger('submit');
        await flush();
    }

    it('hiển thị form CMS LOGIN với ô email, password và nút LOGIN', async () => {
        const { wrapper } = await mountLogin();

        expect(wrapper.text()).toContain('CMS LOGIN');
        expect(byTestId(wrapper, 'login-email').attributes('type')).toBe('email');
        expect(byTestId(wrapper, 'login-password').attributes('type')).toBe('password');
        expect(byTestId(wrapper, 'login-submit').text()).toBe('LOGIN');
    });

    it('để trống thì báo lỗi cả hai ô và không gọi API', async () => {
        const { wrapper } = await mountLogin();

        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('Email is required.');
        expect(wrapper.text()).toContain('Password is required.');
        expect(api.login).not.toHaveBeenCalled();
    });

    it('email sai định dạng bị chặn ở client', async () => {
        const { wrapper } = await mountLogin();

        await submit(wrapper, 'khong-phai-email', 'x');

        expect(wrapper.text()).toContain('valid email');
        expect(api.login).not.toHaveBeenCalled();
    });

    it('đăng nhập thành công: gọi API với email đã trim và chuyển tới dashboard', async () => {
        api.login.mockResolvedValue({ data: { token: 'tok', user: { id: 1, name: 'Me', email: 'user@example.com', role: 'user' } } });
        const { wrapper, router, auth } = await mountLogin();

        await submit(wrapper, '  user@example.com  ');

        expect(api.login).toHaveBeenCalledWith({ email: 'user@example.com', password: 'Password@123' });
        expect(auth.isAuthenticated).toBe(true);
        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('dashboard'));
    });

    it('quay lại trang đang xem dở sau khi đăng nhập (redirect)', async () => {
        api.login.mockResolvedValue({ data: { token: 'tok', user: { id: 1, name: 'Me', email: 'a@example.com', role: 'admin' } } });
        const { wrapper, router } = await mountLogin('/login?redirect=/users?page=2');

        await submit(wrapper);

        // Trang đích được tải động nên điều hướng hoàn tất bất đồng bộ
        await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/users?page=2'));
    });

    it.each(['//evil.com', 'https://evil.com/phish', '/\\evil.com', 'javascript:alert(1)'])('bỏ qua redirect ngoại vi %s (chống open redirect)', async (redirect) => {
        api.login.mockResolvedValue({ data: { token: 'tok', user: { id: 1, name: 'Me', email: 'a@example.com', role: 'user' } } });
        const { wrapper, router } = await mountLogin(`/login?redirect=${encodeURIComponent(redirect)}`);

        await submit(wrapper);

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('dashboard'));
    });

    it('trong lúc gửi: hiện trạng thái loading và khóa nút', async () => {
        const pending = deferred();
        api.login.mockReturnValue(pending.promise);
        const { wrapper } = await mountLogin();

        await byTestId(wrapper, 'login-email').setValue('user@example.com');
        await byTestId(wrapper, 'login-password').setValue('Password@123');
        await wrapper.find('form').trigger('submit');

        expect(byTestId(wrapper, 'login-submit').text()).toBe('Signing in…');
        expect(byTestId(wrapper, 'login-submit').attributes('disabled')).toBeDefined();
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(true);

        pending.reject(apiError(401, { message: 'Invalid credentials.' }));
        await flush();

        expect(byTestId(wrapper, 'login-submit').text()).toBe('LOGIN');
        expect(byTestId(wrapper, 'login-submit').attributes('disabled')).toBeUndefined();
    });

    it('sai thông tin (401): hiện lỗi chung không nói rõ sai email hay mật khẩu, giữ email, xóa mật khẩu', async () => {
        api.login.mockRejectedValue(apiError(401, { message: 'Invalid credentials.' }));
        const { wrapper, auth, router } = await mountLogin();

        await submit(wrapper, 'user@example.com', 'sai-mat-khau');

        expect(byTestId(wrapper, 'login-error').text()).toBe('Invalid email or password.');
        expect(byTestId(wrapper, 'login-email').element.value).toBe('user@example.com');
        expect(byTestId(wrapper, 'login-password').element.value).toBe('');
        expect(auth.isAuthenticated).toBe(false);
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('tài khoản inactive nhận thông báo riêng', async () => {
        api.login.mockRejectedValue(apiError(403, { message: 'Your account is inactive.' }));
        const { wrapper } = await mountLogin();

        await submit(wrapper);

        expect(byTestId(wrapper, 'login-error').text()).toBe('Your account is inactive.');
    });

    it('tài khoản blocked nhận thông báo riêng', async () => {
        api.login.mockRejectedValue(apiError(403, { message: 'Your account has been blocked.' }));
        const { wrapper } = await mountLogin();

        await submit(wrapper);

        expect(byTestId(wrapper, 'login-error').text()).toBe('Your account has been blocked.');
    });

    it('bị giới hạn số lần thử (429) hiện thông báo dễ hiểu', async () => {
        api.login.mockRejectedValue(apiError(429, { message: 'Too Many Attempts.' }));
        const { wrapper } = await mountLogin();

        await submit(wrapper);

        expect(byTestId(wrapper, 'login-error').text()).toMatch(/Too many attempts/);
    });

    it('mất mạng hiện thông báo không kết nối được máy chủ', async () => {
        api.login.mockRejectedValue(networkError());
        const { wrapper } = await mountLogin();

        await submit(wrapper);

        expect(byTestId(wrapper, 'login-error').text()).toMatch(/Cannot reach the server/);
    });

    it('lỗi 422 từ server hiện dưới từng ô', async () => {
        api.login.mockRejectedValue(validationError({ email: ['The email field must be a valid email address.'] }));
        const { wrapper } = await mountLogin();

        await submit(wrapper, 'a@b.co');

        expect(wrapper.find('#login-email-error').text()).toContain('valid email address');
    });

    it('gõ lại thì xóa lỗi cũ', async () => {
        api.login.mockRejectedValue(apiError(401, { message: 'Invalid credentials.' }));
        const { wrapper } = await mountLogin();

        await submit(wrapper);
        expect(byTestId(wrapper, 'login-error').exists()).toBe(true);

        await byTestId(wrapper, 'login-password').setValue('x');

        expect(byTestId(wrapper, 'login-error').exists()).toBe(false);
    });

    it.each([
        ['session_expired', 'Your session has expired. Please log in again.'],
        ['password_changed', 'Your password was changed. Please log in with the new password.'],
    ])('hiện thông báo khi có notice=%s', async (notice, text) => {
        const { wrapper } = await mountLogin(`/login?notice=${notice}`);

        expect(byTestId(wrapper, 'login-notice').text()).toBe(text);
    });

    it('notice lạ trên URL không hiển thị gì (không thể chèn nội dung tùy ý)', async () => {
        const { wrapper } = await mountLogin('/login?notice=%3Cb%3Ehack%3C/b%3E');

        expect(byTestId(wrapper, 'login-notice').exists()).toBe(false);
    });

    it('mật khẩu không bao giờ được ghi vào sessionStorage hay localStorage', async () => {
        api.login.mockResolvedValue({ data: { token: 'tok', user: { id: 1, name: 'Me', email: 'a@example.com', role: 'user' } } });
        const { wrapper } = await mountLogin();

        await submit(wrapper, 'a@example.com', 'Password@123');

        const dump = JSON.stringify({ ...sessionStorage }) + JSON.stringify({ ...localStorage });

        expect(dump).not.toContain('Password@123');
        expect(localStorage.length).toBe(0);
    });
});

// ---------------------------------------------------------------------------
// UMS-025: DASHBOARD
// ---------------------------------------------------------------------------
describe('DashboardPage (UMS-025)', () => {
    const me = { id: 1, name: 'Long', email: 'long@example.com', phone: null, role: 'superadmin', avatar: null, last_login_at: '2026-09-10T10:00:00+00:00' };

    it('SUPERADMIN thấy 4 thẻ thống kê với đúng số liệu', async () => {
        api.getDashboard.mockResolvedValue({ me, stats: { total: 8, active: 5, inactive: 2, blocked: 1 } });
        const { wrapper } = await mountAt(DashboardPage);

        expect(byTestId(wrapper, 'card-total').text()).toContain('Total Users');
        expect(byTestId(wrapper, 'card-total').text()).toContain('8');
        expect(byTestId(wrapper, 'card-active').text()).toContain('Active Users');
        expect(byTestId(wrapper, 'card-active').text()).toContain('5');
        expect(byTestId(wrapper, 'card-inactive').text()).toContain('2');
        expect(byTestId(wrapper, 'card-blocked').text()).toContain('1');
    });

    it('số 0 vẫn được hiển thị chứ không bị ẩn', async () => {
        api.getDashboard.mockResolvedValue({ me, stats: { total: 0, active: 0, inactive: 0, blocked: 0 } });
        const { wrapper } = await mountAt(DashboardPage);

        expect(wrapper.findAll('[data-testid^="card-"]')).toHaveLength(4);
        expect(byTestId(wrapper, 'card-blocked').text()).toContain('0');
    });

    it('USER thường chỉ thấy dashboard cá nhân, không có thẻ thống kê', async () => {
        api.getDashboard.mockResolvedValue({ me: { ...me, role: 'user', name: 'John' } });
        const { wrapper } = await mountAt(DashboardPage, { role: 'user' });

        expect(byTestId(wrapper, 'stat-cards').exists()).toBe(false);
        expect(byTestId(wrapper, 'me-card').text()).toContain('John');
        expect(wrapper.text()).toContain('Welcome back, John.');
    });

    it('hiển thị thông tin tài khoản và lần đăng nhập gần nhất', async () => {
        api.getDashboard.mockResolvedValue({ me, stats: { total: 1, active: 1, inactive: 0, blocked: 0 } });
        const { wrapper } = await mountAt(DashboardPage);

        expect(byTestId(wrapper, 'me-card').text()).toContain('long@example.com');
        expect(byTestId(wrapper, 'me-card').text()).toMatch(/2026/);
    });

    it('chưa từng đăng nhập thì Last login là dấu gạch', async () => {
        api.getDashboard.mockResolvedValue({ me: { ...me, last_login_at: null }, stats: { total: 0, active: 0, inactive: 0, blocked: 0 } });
        const { wrapper } = await mountAt(DashboardPage);

        expect(byTestId(wrapper, 'me-card').text()).toContain('—');
    });

    it('có trạng thái loading', async () => {
        api.getDashboard.mockReturnValue(deferred().promise);
        const { wrapper } = await mountAt(DashboardPage);

        expect(byTestId(wrapper, 'state-loading').exists()).toBe(true);
        expect(byTestId(wrapper, 'stat-cards').exists()).toBe(false);
    });

    it('lỗi API hiện thông báo và nút thử lại hoạt động', async () => {
        api.getDashboard.mockRejectedValueOnce(apiError(500)).mockResolvedValueOnce({ me, stats: { total: 3, active: 3, inactive: 0, blocked: 0 } });
        const { wrapper } = await mountAt(DashboardPage);

        expect(byTestId(wrapper, 'state-error').text()).toContain('Server error');

        await byTestId(wrapper, 'retry-button').trigger('click');
        await flush();

        expect(byTestId(wrapper, 'card-total').text()).toContain('3');
        expect(api.getDashboard).toHaveBeenCalledTimes(2);
    });

    it('đồng bộ thông tin mới nhất vào store để sidebar hiển thị', async () => {
        api.getDashboard.mockResolvedValue({ me: { ...me, avatar: 'http://x/a.png' }, stats: { total: 0, active: 0, inactive: 0, blocked: 0 } });
        const { auth } = await mountAt(DashboardPage);

        expect(auth.user.avatar).toBe('http://x/a.png');
    });
});

// ---------------------------------------------------------------------------
// UMS-026: SIDEBAR THEO ROLE
// ---------------------------------------------------------------------------
describe('CmsLayout - sidebar và menu theo role (UMS-026)', () => {
    // Layout phải được render qua RouterView của App: mount trực tiếp thì RouterView bên trong sẽ tự render lại chính layout
    const mountLayout = (options = {}) => {
        const role = options.role ?? 'user';

        // Dashboard đồng bộ `me` từ server vào store nên mock phải khớp role/tên đang đăng nhập
        api.getDashboard.mockResolvedValue({
            me: { id: 1, name: 'Me', email: 'me@example.com', phone: null, role, avatar: null, last_login_at: null, ...(options.me ?? {}) },
        });

        return mountApp({ path: '/dashboard', ...options });
    };
    const menuOf = (wrapper) => wrapper.findAll('nav[aria-label="Main"] a, nav[aria-label="Main"] button').map((el) => el.text());

    it.each([
        ['superadmin', ['Dashboard', 'User Management', 'Audit Logs', 'Profile', 'Logout']],
        ['admin', ['Dashboard', 'User Management', 'Profile', 'Logout']],
        ['user', ['Dashboard', 'Profile', 'Logout']],
    ])('%s thấy menu: %j', async (role, expected) => {
        const { wrapper } = await mountLayout({ role });

        expect(menuOf(wrapper)).toEqual(expected);
    });

    it('USER không có User Management, ADMIN/USER không có Audit Logs', async () => {
        const user = await mountLayout({ role: 'user' });
        const admin = await mountLayout({ role: 'admin' });

        expect(byTestId(user.wrapper, 'nav-users').exists()).toBe(false);
        expect(byTestId(user.wrapper, 'nav-audit-logs').exists()).toBe(false);
        expect(byTestId(admin.wrapper, 'nav-users').exists()).toBe(true);
        expect(byTestId(admin.wrapper, 'nav-audit-logs').exists()).toBe(false);
    });

    it('hiển thị tên và role của người đăng nhập', async () => {
        const { wrapper } = await mountLayout({ role: 'admin', me: { name: 'Tran Admin' } });

        expect(byTestId(wrapper, 'sidebar-user-name').text()).toBe('Tran Admin');
        expect(byTestId(wrapper, 'sidebar-user-role').text()).toBe('Admin');
    });

    it('mục menu trỏ đúng route', async () => {
        const { wrapper } = await mountLayout({ role: 'superadmin' });

        expect(byTestId(wrapper, 'nav-users').attributes('href')).toBe('/users');
        expect(byTestId(wrapper, 'nav-audit-logs').attributes('href')).toBe('/audit-logs');
        expect(byTestId(wrapper, 'nav-profile').attributes('href')).toBe('/profile');
    });

    it('Logout gọi API, xóa phiên và chuyển về trang login', async () => {
        api.logout.mockResolvedValue({});
        const { wrapper, auth, router } = await mountLayout({ role: 'user' });

        await byTestId(wrapper, 'nav-logout').trigger('click');
        await flush();

        expect(api.logout).toHaveBeenCalledTimes(1);
        expect(auth.isAuthenticated).toBe(false);
        expect(sessionStorage.getItem('cms.token')).toBeNull();
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('Logout vẫn đăng xuất phía client khi API lỗi', async () => {
        api.logout.mockRejectedValue(networkError());
        const { wrapper, auth, router } = await mountLayout({ role: 'user' });

        await byTestId(wrapper, 'nav-logout').trigger('click');
        await flush();

        expect(auth.isAuthenticated).toBe(false);
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('nút Menu trên màn hình nhỏ mở/đóng sidebar', async () => {
        const { wrapper } = await mountLayout({ role: 'user' });

        expect(byTestId(wrapper, 'menu-toggle').attributes('aria-expanded')).toBe('false');
        expect(byTestId(wrapper, 'sidebar').classes()).toContain('hidden');

        await byTestId(wrapper, 'menu-toggle').trigger('click');

        expect(byTestId(wrapper, 'menu-toggle').attributes('aria-expanded')).toBe('true');
        expect(byTestId(wrapper, 'sidebar').classes()).not.toContain('hidden');
    });

    it('tên chứa HTML trong sidebar được hiển thị dạng chữ', async () => {
        const { wrapper } = await mountLayout({ role: 'user', me: { name: '<img src=x onerror=alert(1)>' } });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(byTestId(wrapper, 'sidebar-user-name').text()).toContain('<img');
    });
});
