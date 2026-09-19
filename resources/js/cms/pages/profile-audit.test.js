import * as api from '../api';
import ProfilePage from './ProfilePage.vue';
import AuditLogsPage from './AuditLogsPage.vue';
import { useToastStore } from '../stores/toast';
import { allByTestId, apiError, byTestId, deferred, flush, listResponse, mountAt, validationError } from '../test/helpers';

vi.mock('../api');

beforeEach(() => vi.clearAllMocks());

// ---------------------------------------------------------------------------
// UMS-034: PROFILE
// ---------------------------------------------------------------------------
describe('ProfilePage (UMS-034)', () => {
    const profile = { id: 1, name: 'Nguyen Long', email: 'long@example.com', phone: '0900000000', role: 'admin', avatar: null };

    beforeEach(() => {
        api.getProfile.mockResolvedValue(profile);
    });

    const mountProfile = (options = {}) => mountAt(ProfilePage, { path: '/profile', role: 'admin', ...options });

    function pickFile(wrapper, file) {
        const input = byTestId(wrapper, 'profile-avatar');

        Object.defineProperty(input.element, 'files', { value: [file], configurable: true });

        return input.trigger('change');
    }

    const png = (size = 1024, name = 'a.png') => new File([new Uint8Array(size)], name, { type: 'image/png' });

    it('hiển thị Avatar, Name, Email, Phone, Role và nút Save Changes', async () => {
        const { wrapper } = await mountProfile();

        expect(byTestId(wrapper, 'profile-name').element.value).toBe('Nguyen Long');
        expect(byTestId(wrapper, 'profile-phone').element.value).toBe('0900000000');
        expect(byTestId(wrapper, 'profile-email').element.value).toBe('long@example.com');
        expect(byTestId(wrapper, 'role-badge').text()).toBe('Admin');
        expect(byTestId(wrapper, 'profile-save').text()).toBe('Save Changes');
        expect(wrapper.text()).toContain('Change Password');
    });

    it('email không sửa được (disabled) và role chỉ để đọc', async () => {
        const { wrapper } = await mountProfile();

        expect(byTestId(wrapper, 'profile-email').attributes('disabled')).toBeDefined();
        expect(wrapper.find('select[id="profile-role"]').exists()).toBe(false);
        expect(wrapper.find('input[id="profile-role"]').exists()).toBe(false);
    });

    it('có trạng thái loading và lỗi kèm nút thử lại', async () => {
        api.getProfile.mockReturnValueOnce(deferred().promise);
        const loading = await mountProfile();

        expect(byTestId(loading.wrapper, 'state-loading').exists()).toBe(true);

        api.getProfile.mockRejectedValueOnce(apiError(500)).mockResolvedValueOnce(profile);
        const failed = await mountProfile();

        expect(byTestId(failed.wrapper, 'state-error').exists()).toBe(true);

        await byTestId(failed.wrapper, 'retry-button').trigger('click');
        await flush();

        expect(byTestId(failed.wrapper, 'profile-name').exists()).toBe(true);
    });

    it('lưu tên và số điện thoại: gọi API, cập nhật store, hiện thông báo', async () => {
        api.updateProfile.mockResolvedValue({ success: true, message: 'Profile updated successfully.', data: { ...profile, name: 'Ten Moi', phone: '0911111111' } });
        const { wrapper, auth } = await mountProfile();

        await byTestId(wrapper, 'profile-name').setValue('  Ten Moi ');
        await byTestId(wrapper, 'profile-phone').setValue('0911111111');
        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        expect(api.updateProfile).toHaveBeenCalledWith({ name: 'Ten Moi', phone: '0911111111' }, { avatarFile: null, removeAvatar: false });
        expect(auth.user.name).toBe('Ten Moi');
        expect(useToastStore().toasts.map((t) => t.message)).toContain('Profile updated successfully.');
    });

    it('không bao giờ gửi email, role hay status lên API', async () => {
        api.updateProfile.mockResolvedValue({ message: 'ok', data: profile });
        const { wrapper } = await mountProfile();

        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        expect(Object.keys(api.updateProfile.mock.calls[0][0])).toEqual(['name', 'phone']);
    });

    it('validate tên trước khi gửi', async () => {
        const { wrapper } = await mountProfile();

        await byTestId(wrapper, 'profile-name').setValue('');
        await wrapper.find('[data-testid="profile-form"]').trigger('submit');

        expect(wrapper.text()).toContain('Name is required.');
        expect(api.updateProfile).not.toHaveBeenCalled();
    });

    it('lỗi 422 từ server hiện dưới ô tương ứng, lỗi khác hiện thông báo chung', async () => {
        api.updateProfile.mockRejectedValueOnce(validationError({ phone: ['The phone field must not be greater than 20 characters.'] }));
        const { wrapper } = await mountProfile();

        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        expect(wrapper.find('#profile-phone-error').text()).toContain('20 characters');

        api.updateProfile.mockRejectedValueOnce(apiError(500));
        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        expect(byTestId(wrapper, 'form-error').text()).toBe('Server error. Please try again later.');
    });

    it('nút lưu có loading khi đang gửi', async () => {
        api.updateProfile.mockReturnValue(deferred().promise);
        const { wrapper } = await mountProfile();

        await wrapper.find('[data-testid="profile-form"]').trigger('submit');

        expect(byTestId(wrapper, 'profile-save').attributes('disabled')).toBeDefined();
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(true);
    });

    // ---- avatar ----
    it('chọn ảnh hợp lệ rồi lưu thì gửi kèm file', async () => {
        api.updateProfile.mockResolvedValue({ message: 'ok', data: { ...profile, avatar: 'http://x/storage/avatars/new.png' } });
        const { wrapper } = await mountProfile();
        const file = png();

        await pickFile(wrapper, file);
        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        expect(api.updateProfile.mock.calls[0][1]).toEqual({ avatarFile: file, removeAvatar: false });
        expect(wrapper.find('img').attributes('src')).toBe('http://x/storage/avatars/new.png');
    });

    it.each([
        ['không phải ảnh (PDF)', new File(['x'], 'cv.pdf', { type: 'application/pdf' }), /JPG, PNG or WebP/],
        ['SVG', new File(['<svg/>'], 'a.svg', { type: 'image/svg+xml' }), /JPG, PNG or WebP/],
        ['lớn hơn 2 MB', new File([new Uint8Array(2 * 1024 * 1024 + 1)], 'big.png', { type: 'image/png' }), /2 MB/],
    ])('từ chối file %s ngay ở client', async (_name, file, message) => {
        const { wrapper } = await mountProfile();

        await pickFile(wrapper, file);

        expect(byTestId(wrapper, 'field-error').text()).toMatch(message);

        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        // File không hợp lệ không được đính kèm vào request
        expect(api.updateProfile.mock.calls.at(-1)?.[1]?.avatarFile ?? null).toBeNull();
    });

    it('ô chọn ảnh chỉ cho phép JPG, PNG, WebP', async () => {
        const { wrapper } = await mountProfile();

        expect(byTestId(wrapper, 'profile-avatar').attributes('accept')).toBe('image/png,image/jpeg,image/webp');
    });

    it('Remove photo chỉ hiện khi đang có ảnh và gửi removeAvatar', async () => {
        api.getProfile.mockResolvedValue({ ...profile, avatar: 'http://x/a.png' });
        api.updateProfile.mockResolvedValue({ message: 'ok', data: { ...profile, avatar: null } });
        const { wrapper } = await mountProfile();

        await byTestId(wrapper, 'remove-avatar').trigger('click');
        expect(wrapper.find('img').exists()).toBe(false);

        await wrapper.find('[data-testid="profile-form"]').trigger('submit');
        await flush();

        expect(api.updateProfile.mock.calls[0][1]).toEqual({ avatarFile: null, removeAvatar: true });
    });

    it('không có ảnh thì không có nút Remove photo', async () => {
        const { wrapper } = await mountProfile();

        expect(byTestId(wrapper, 'remove-avatar').exists()).toBe(false);
    });

    // ---- đổi mật khẩu ----
    async function changePasswordFlow(wrapper) {
        await byTestId(wrapper, 'cp-current').setValue('OldPassword@123');
        await byTestId(wrapper, 'cp-new').setValue('NewPassword@123');
        await byTestId(wrapper, 'cp-confirm').setValue('NewPassword@123');
        await wrapper.find('[data-testid="change-password-form"]').trigger('submit');
        await flush();
    }

    it('đổi mật khẩu thành công: xóa phiên và chuyển về login kèm thông báo (backend đã thu hồi mọi token)', async () => {
        api.changePassword.mockResolvedValue({ success: true });
        const { wrapper, auth, router } = await mountProfile();

        await changePasswordFlow(wrapper);

        expect(api.changePassword).toHaveBeenCalledWith({
            current_password: 'OldPassword@123', password: 'NewPassword@123', password_confirmation: 'NewPassword@123',
        });
        expect(auth.isAuthenticated).toBe(false);
        expect(sessionStorage.getItem('cms.token')).toBeNull();

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'));
        expect(router.currentRoute.value.query.notice).toBe('password_changed');
    });

    it('đổi mật khẩu thất bại thì vẫn ở lại và giữ phiên đăng nhập', async () => {
        api.changePassword.mockRejectedValue(validationError({ current_password: ['The current password is incorrect.'] }));
        const { wrapper, auth, router } = await mountProfile();

        await changePasswordFlow(wrapper);

        expect(wrapper.find('#current-password-error').text()).toBe('The current password is incorrect.');
        expect(auth.isAuthenticated).toBe(true);
        expect(router.currentRoute.value.name).toBe('profile');
    });
});

// ---------------------------------------------------------------------------
// AUDIT LOGS
// ---------------------------------------------------------------------------
describe('AuditLogsPage', () => {
    const log = (overrides = {}) => ({
        id: 1,
        action: 'UPDATE_USER',
        user: { id: 1, name: 'Super Admin', email: 'superadmin@example.com' },
        target: { type: 'User', id: 7 },
        old_values: { name: 'Cu' },
        new_values: { name: 'Moi' },
        ip_address: '203.0.113.7',
        user_agent: 'PestAgent/1.0',
        created_at: '2026-09-10T10:00:00+00:00',
        ...overrides,
    });

    beforeEach(() => {
        api.listAuditLogs.mockResolvedValue(listResponse([log()]));
    });

    const mountLogs = () => mountAt(AuditLogsPage, { path: '/audit-logs', role: 'superadmin' });
    const lastParams = () => api.listAuditLogs.mock.calls.at(-1)[0];

    it('hiển thị bảng log với thời gian, action, người thực hiện, đối tượng, IP', async () => {
        const { wrapper } = await mountLogs();
        const row = byTestId(wrapper, 'audit-row-1');

        expect(row.text()).toContain('UPDATE_USER');
        expect(row.text()).toContain('Super Admin (superadmin@example.com)');
        expect(row.text()).toContain('User #7');
        expect(row.text()).toContain('203.0.113.7');
        expect(row.text()).toMatch(/2026/);
    });

    it('lần đầu gọi API trang 1 với 20 dòng và không lọc', async () => {
        await mountLogs();

        expect(lastParams()).toMatchObject({ user: '', action: '', date_from: '', date_to: '', page: 1, per_page: 20 });
    });

    it('log không có người thực hiện hoặc đối tượng hiện dấu gạch', async () => {
        api.listAuditLogs.mockResolvedValue(listResponse([log({ id: 2, user: null, target: null, old_values: null, new_values: null, ip_address: null })]));
        const { wrapper } = await mountLogs();

        expect(byTestId(wrapper, 'audit-row-2').text().match(/—/g)).toHaveLength(4);
    });

    it('xem thay đổi Before/After', async () => {
        const { wrapper } = await mountLogs();
        const text = byTestId(wrapper, 'audit-row-1').text();

        expect(text).toContain('Before:');
        expect(text).toContain('"name": "Cu"');
        expect(text).toContain('After:');
        expect(text).toContain('"name": "Moi"');
    });

    it('nội dung log chứa HTML được hiển thị dạng chữ (chống XSS)', async () => {
        api.listAuditLogs.mockResolvedValue(listResponse([log({ new_values: { name: '<img src=x onerror=alert(1)>' }, user: { id: 1, name: '<script>alert(1)</script>', email: 'a@b.c' } })]));
        const { wrapper } = await mountLogs();

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(byTestId(wrapper, 'audit-row-1').text()).toContain('<img src=x onerror=alert(1)>');
    });

    it('bộ lọc gửi đúng tham số và về trang 1', async () => {
        const { wrapper } = await mountLogs();

        await byTestId(wrapper, 'al-user').setValue('7');
        await byTestId(wrapper, 'al-action').setValue('DELETE_USER');
        await byTestId(wrapper, 'al-from').setValue('2026-09-01');
        await byTestId(wrapper, 'al-to').setValue('2026-09-30');
        await byTestId(wrapper, 'al-apply').trigger('click');
        await flush();

        expect(lastParams()).toMatchObject({ user: 7, action: 'DELETE_USER', date_from: '2026-09-01', date_to: '2026-09-30', page: 1 });
    });

    it('dropdown action có đủ 9 hành động của backend', async () => {
        const { wrapper } = await mountLogs();
        const options = byTestId(wrapper, 'al-action').findAll('option').map((o) => o.text());

        expect(options).toEqual([
            'All', 'LOGIN', 'LOGOUT', 'CREATE_USER', 'UPDATE_USER', 'DELETE_USER', 'CHANGE_STATUS', 'RESET_PASSWORD', 'UPDATE_PROFILE', 'CHANGE_PASSWORD',
        ]);
    });

    it('ngày kết thúc trước ngày bắt đầu bị chặn ở client', async () => {
        const { wrapper } = await mountLogs();
        const calls = api.listAuditLogs.mock.calls.length;

        await byTestId(wrapper, 'al-from').setValue('2026-09-12');
        await byTestId(wrapper, 'al-to').setValue('2026-09-10');
        await byTestId(wrapper, 'al-apply').trigger('click');
        await flush();

        expect(byTestId(wrapper, 'al-filter-error').text()).toMatch(/must not be before/);
        expect(api.listAuditLogs.mock.calls.length).toBe(calls);
    });

    it('Reset xóa bộ lọc và tải lại', async () => {
        const { wrapper } = await mountLogs();

        await byTestId(wrapper, 'al-action').setValue('LOGIN');
        await byTestId(wrapper, 'al-apply').trigger('click');
        await byTestId(wrapper, 'al-reset').trigger('click');
        await flush();

        expect(lastParams()).toMatchObject({ action: '', user: '', date_from: '', date_to: '' });
        expect(byTestId(wrapper, 'al-action').element.value).toBe('');
    });

    it('phân trang: chuyển trang và đổi số dòng', async () => {
        api.listAuditLogs.mockResolvedValue(listResponse([log()], { total: 100, last_page: 5 }));
        const { wrapper } = await mountLogs();

        await byTestId(wrapper, 'page-2').trigger('click');
        await flush();
        expect(lastParams().page).toBe(2);

        await byTestId(wrapper, 'per-page-select').setValue('50');
        await flush();
        expect(lastParams()).toMatchObject({ per_page: 50, page: 1 });
    });

    it('có trạng thái loading, rỗng và lỗi', async () => {
        api.listAuditLogs.mockReturnValueOnce(deferred().promise);
        const loading = await mountLogs();

        expect(byTestId(loading.wrapper, 'state-loading').exists()).toBe(true);

        api.listAuditLogs.mockResolvedValueOnce(listResponse([]));
        const empty = await mountLogs();

        expect(byTestId(empty.wrapper, 'state-empty').text()).toContain('No audit logs found.');

        api.listAuditLogs.mockRejectedValueOnce(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const failed = await mountLogs();

        expect(byTestId(failed.wrapper, 'state-error').text()).toContain('permission');
    });

    it('bỏ qua phản hồi cũ đến muộn', async () => {
        const slow = deferred();
        api.listAuditLogs.mockReturnValueOnce(slow.promise).mockResolvedValueOnce(listResponse([log({ id: 99 })]));
        const { wrapper } = await mountLogs();

        await byTestId(wrapper, 'al-apply').trigger('click');
        await flush();

        slow.resolve(listResponse([log({ id: 1 })]));
        await flush();

        expect(allByTestId(wrapper, 'audit-row-99')).toHaveLength(1);
        expect(allByTestId(wrapper, 'audit-row-1')).toHaveLength(0);
    });
});
