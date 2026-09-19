import * as api from '../../api';
import UserListPage from './UserListPage.vue';
import UserCreatePage from './UserCreatePage.vue';
import UserDetailPage from './UserDetailPage.vue';
import UserEditPage from './UserEditPage.vue';
import { useToastStore } from '../../stores/toast';
import {
    NO_ABILITIES, allByTestId, apiError, byTestId, deferred, flush, listResponse, makeMeta, makeUser, mountAt, networkError, validationError,
} from '../../test/helpers';

vi.mock('../../api');

beforeEach(() => vi.clearAllMocks());

const lastCall = (mock) => mock.mock.calls.at(-1)[0];

// ---------------------------------------------------------------------------
// UMS-027: DANH SÁCH USER
// ---------------------------------------------------------------------------
describe('UserListPage (UMS-027)', () => {
    const alice = makeUser({ id: 1, name: 'Alice Nguyen', email: 'alice@example.com', role: 'user', status: 'active' });
    const bob = makeUser({ id: 2, name: 'Bob Tran', email: 'bob@example.com', role: 'admin', status: 'blocked' });

    // Dùng ngoặc nhọn: nếu trả về hàm mock thì Vitest sẽ coi đó là hàm dọn dẹp và gọi nó sau mỗi test
    beforeEach(() => {
        api.listUsers.mockResolvedValue(listResponse([alice, bob]));
    });

    const mountList = (options = {}) => mountAt(UserListPage, { path: '/users', ...options });

    it('có tiêu đề, nút Create User, ô Search và bộ lọc Role/Status', async () => {
        const { wrapper } = await mountList();

        expect(wrapper.text()).toContain('User Management');
        expect(byTestId(wrapper, 'create-user').text()).toContain('Create User');
        expect(byTestId(wrapper, 'create-user').attributes('href')).toBe('/users/create');
        expect(byTestId(wrapper, 'filter-search').exists()).toBe(true);
        expect(byTestId(wrapper, 'filter-role').exists()).toBe(true);
        expect(byTestId(wrapper, 'filter-status').exists()).toBe(true);
    });

    it('tải danh sách từ API và hiển thị các cột Name, Email, Role, Status, Created At, Actions', async () => {
        const { wrapper } = await mountList();

        expect(wrapper.findAll('th').map((th) => th.text())).toEqual(['Name', 'Email', 'Role', 'Status', 'Created At', 'Actions']);
        expect(allByTestId(wrapper, 'user-row-1')).toHaveLength(1);

        const row = byTestId(wrapper, 'user-row-2');

        expect(row.text()).toContain('Bob Tran');
        expect(row.text()).toContain('bob@example.com');
        expect(row.text()).toContain('Admin');
        expect(row.text()).toContain('Blocked');
        expect(row.text()).toMatch(/2026/);
    });

    it('lần đầu gọi API với trang 1 và không có bộ lọc', async () => {
        await mountList();

        expect(lastCall(api.listUsers)).toMatchObject({ search: '', role: 'all', status: 'all', page: 1 });
    });

    it('có trạng thái loading trong lúc chờ', async () => {
        api.listUsers.mockReturnValue(deferred().promise);
        const { wrapper } = await mountList();

        expect(byTestId(wrapper, 'state-loading').exists()).toBe(true);
        expect(byTestId(wrapper, 'users-table').exists()).toBe(false);
    });

    it('trạng thái rỗng khi không có user', async () => {
        api.listUsers.mockResolvedValue(listResponse([]));
        const { wrapper } = await mountList();

        expect(byTestId(wrapper, 'state-empty').text()).toContain('No users found.');
        expect(byTestId(wrapper, 'clear-filters').exists()).toBe(false);
    });

    it('trạng thái rỗng kèm bộ lọc đang bật thì có nút Clear filters', async () => {
        api.listUsers.mockResolvedValue(listResponse([]));
        const { wrapper } = await mountList({ path: '/users?search=zzz' });

        expect(byTestId(wrapper, 'clear-filters').exists()).toBe(true);

        api.listUsers.mockResolvedValue(listResponse([alice]));
        await byTestId(wrapper, 'clear-filters').trigger('click');
        await vi.waitFor(() => expect(lastCall(api.listUsers).search).toBe(''));
    });

    it('trạng thái lỗi API và nút thử lại', async () => {
        api.listUsers.mockRejectedValueOnce(apiError(500, { message: 'SQLSTATE nội bộ' })).mockResolvedValueOnce(listResponse([alice]));
        const { wrapper } = await mountList();

        expect(byTestId(wrapper, 'state-error').text()).toContain('Server error. Please try again later.');
        expect(wrapper.text()).not.toContain('SQLSTATE');

        await byTestId(wrapper, 'retry-button').trigger('click');
        await flush();

        expect(byTestId(wrapper, 'user-row-1').exists()).toBe(true);
    });

    it('lỗi mạng hiện thông báo không kết nối được', async () => {
        api.listUsers.mockRejectedValue(networkError());
        const { wrapper } = await mountList();

        expect(byTestId(wrapper, 'state-error').text()).toContain('Cannot reach the server');
    });

    // ---- tìm kiếm & lọc ----
    it('Search gửi từ khóa lên API và về trang 1', async () => {
        const { wrapper, router } = await mountList({ path: '/users?page=3' });

        await byTestId(wrapper, 'filter-search').setValue('  long  ');
        await wrapper.find('form[role="search"]').trigger('submit');

        await vi.waitFor(() => expect(lastCall(api.listUsers)).toMatchObject({ search: 'long', page: 1 }));
        expect(router.currentRoute.value.query).toEqual({ search: 'long' });
    });

    it('lọc theo role và status, kết hợp được với từ khóa', async () => {
        const { wrapper } = await mountList();

        await byTestId(wrapper, 'filter-search').setValue('long');
        await byTestId(wrapper, 'filter-role').setValue('user');
        await byTestId(wrapper, 'filter-status').setValue('active');
        await wrapper.find('form[role="search"]').trigger('submit');

        await vi.waitFor(() => expect(lastCall(api.listUsers)).toMatchObject({ search: 'long', role: 'user', status: 'active', page: 1 }));
    });

    it('khôi phục bộ lọc, trang và số dòng từ URL (F5, nút Back)', async () => {
        const { wrapper } = await mountList({ path: '/users?search=abc&role=user&status=blocked&page=2&per_page=50' });

        expect(lastCall(api.listUsers)).toEqual({ search: 'abc', role: 'user', status: 'blocked', page: 2, per_page: 50 });
        expect(byTestId(wrapper, 'filter-search').element.value).toBe('abc');
        expect(byTestId(wrapper, 'filter-role').element.value).toBe('user');
        expect(byTestId(wrapper, 'filter-status').element.value).toBe('blocked');
    });

    it('giá trị page rác trên URL quay về trang 1', async () => {
        await mountList({ path: '/users?page=abc' });

        expect(lastCall(api.listUsers).page).toBe(1);
    });

    it('bấm Search khi bộ lọc không đổi thì tải lại', async () => {
        const { wrapper } = await mountList();

        expect(api.listUsers).toHaveBeenCalledTimes(1);

        await wrapper.find('form[role="search"]').trigger('submit');
        await flush();

        expect(api.listUsers).toHaveBeenCalledTimes(2);
    });

    it('dropdown role của ADMIN chỉ có All và User', async () => {
        const { wrapper } = await mountList({ role: 'admin' });

        expect(byTestId(wrapper, 'filter-role').findAll('option').map((o) => o.text())).toEqual(['All', 'User']);
    });

    it('dropdown role của SUPERADMIN có thêm Admin và Super Admin', async () => {
        const { wrapper } = await mountList({ role: 'superadmin' });

        expect(byTestId(wrapper, 'filter-role').findAll('option').map((o) => o.text())).toEqual(['All', 'Admin', 'User', 'Super Admin']);
    });

    it('dropdown status có đủ All, Active, Inactive, Blocked', async () => {
        const { wrapper } = await mountList();

        expect(byTestId(wrapper, 'filter-status').findAll('option').map((o) => o.text())).toEqual(['All', 'Active', 'Inactive', 'Blocked']);
    });

    // ---- phân trang ----
    it('hiển thị phân trang và chuyển trang gọi API đúng trang', async () => {
        api.listUsers.mockResolvedValue(listResponse([alice], { total: 45, last_page: 3, per_page: 20 }));
        const { wrapper, router } = await mountList();

        expect(byTestId(wrapper, 'pagination-summary').text()).toBe('Showing 1–20 of 45');

        await byTestId(wrapper, 'page-2').trigger('click');

        await vi.waitFor(() => expect(lastCall(api.listUsers).page).toBe(2));
        expect(router.currentRoute.value.query.page).toBe('2');
    });

    it('Next chuyển sang trang kế tiếp, giữ nguyên bộ lọc', async () => {
        api.listUsers.mockResolvedValue(listResponse([alice], { total: 45, last_page: 3 }));
        const { wrapper } = await mountList({ path: '/users?search=a&role=user' });

        await byTestId(wrapper, 'page-next').trigger('click');

        await vi.waitFor(() => expect(lastCall(api.listUsers)).toMatchObject({ search: 'a', role: 'user', page: 2 }));
    });

    it('đổi số dòng mỗi trang gọi API với per_page mới và về trang 1', async () => {
        api.listUsers.mockResolvedValue(listResponse([alice], { total: 200, last_page: 10 }));
        const { wrapper } = await mountList({ path: '/users?page=4' });

        await byTestId(wrapper, 'per-page-select').setValue('50');

        await vi.waitFor(() => expect(lastCall(api.listUsers)).toMatchObject({ per_page: 50, page: 1 }));
    });

    it('đang đứng ở trang không còn dữ liệu thì tự lùi về trang cuối', async () => {
        api.listUsers
            .mockResolvedValueOnce(listResponse([], { current_page: 3, last_page: 2, total: 30 }))
            .mockResolvedValue(listResponse([alice], { current_page: 2, last_page: 2, total: 30 }));
        const { router } = await mountList({ path: '/users?page=3' });

        await vi.waitFor(() => expect(lastCall(api.listUsers).page).toBe(2));
        expect(router.currentRoute.value.query.page).toBe('2');
    });

    it('bỏ qua phản hồi cũ đến muộn (chống race condition)', async () => {
        const slow = deferred();
        api.listUsers.mockReturnValueOnce(slow.promise).mockResolvedValueOnce(listResponse([bob]));
        const { wrapper } = await mountList();

        await byTestId(wrapper, 'filter-search').setValue('bob');
        await wrapper.find('form[role="search"]').trigger('submit');
        await vi.waitFor(() => expect(byTestId(wrapper, 'user-row-2').exists()).toBe(true));

        slow.resolve(listResponse([alice]));
        await flush();

        expect(byTestId(wrapper, 'user-row-2').exists()).toBe(true);
        expect(byTestId(wrapper, 'user-row-1').exists()).toBe(false);
    });

    // ---- hành động theo abilities ----
    it('hiện đủ 5 hành động khi API cho phép tất cả', async () => {
        const { wrapper } = await mountList();
        const row = byTestId(wrapper, 'user-row-1');

        expect(row.find('[data-testid="action-view"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-edit"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-status"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-reset"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-delete"]').exists()).toBe(true);
    });

    it('chỉ còn View khi không có quyền nào (vd. SUPERADMIN xem SUPERADMIN khác)', async () => {
        api.listUsers.mockResolvedValue(listResponse([makeUser({ id: 3, role: 'superadmin', abilities: NO_ABILITIES })]));
        const { wrapper } = await mountList();
        const row = byTestId(wrapper, 'user-row-3');

        expect(row.find('[data-testid="action-view"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-edit"]').exists()).toBe(false);
        expect(row.find('[data-testid="action-status"]').exists()).toBe(false);
        expect(row.find('[data-testid="action-reset"]').exists()).toBe(false);
        expect(row.find('[data-testid="action-delete"]').exists()).toBe(false);
    });

    it('từng quyền điều khiển đúng nút của nó', async () => {
        api.listUsers.mockResolvedValue(listResponse([makeUser({ id: 4, abilities: { update: true, delete: false, change_status: false, reset_password: true } })]));
        const { wrapper } = await mountList();
        const row = byTestId(wrapper, 'user-row-4');

        expect(row.find('[data-testid="action-edit"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-reset"]').exists()).toBe(true);
        expect(row.find('[data-testid="action-delete"]').exists()).toBe(false);
        expect(row.find('[data-testid="action-status"]').exists()).toBe(false);
    });

    it('View và Edit trỏ đúng đường dẫn', async () => {
        const { wrapper } = await mountList();
        const row = byTestId(wrapper, 'user-row-1');

        expect(row.find('[data-testid="action-view"]').attributes('href')).toBe('/users/1');
        expect(row.find('[data-testid="action-edit"]').attributes('href')).toBe('/users/1/edit');
    });

    it('tên và email chứa HTML được hiển thị dạng chữ (chống XSS)', async () => {
        api.listUsers.mockResolvedValue(listResponse([makeUser({ id: 9, name: '<img src=x onerror=alert(1)>', email: '<script>alert(1)</script>@x.com' })]));
        const { wrapper } = await mountList();

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(byTestId(wrapper, 'user-row-9').text()).toContain('<img src=x onerror=alert(1)>');
    });

    // ---- xóa (UMS-031) ----
    it('Delete mở hộp xác nhận với tên user, Cancel đóng mà không xóa', async () => {
        const { wrapper } = await mountList();

        await byTestId(wrapper, 'user-row-1').find('[data-testid="action-delete"]').trigger('click');

        expect(byTestId(wrapper, 'delete-target').text()).toBe('"Alice Nguyen"');

        await byTestId(wrapper, 'cancel-button').trigger('click');

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(api.deleteUser).not.toHaveBeenCalled();
    });

    it('xác nhận xóa: gọi API, hiện thông báo thành công, đóng hộp thoại và tải lại danh sách', async () => {
        api.deleteUser.mockResolvedValue({ success: true, message: 'User deleted successfully.' });
        const { wrapper } = await mountList();

        await byTestId(wrapper, 'user-row-1').find('[data-testid="action-delete"]').trigger('click');
        await byTestId(wrapper, 'confirm-delete').trigger('click');
        await flush();

        expect(api.deleteUser).toHaveBeenCalledWith(1);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(api.listUsers).toHaveBeenCalledTimes(2);
        expect(useToastStore().toasts.map((t) => t.message)).toContain('User deleted successfully.');
    });

    it('xóa lỗi thì hộp thoại vẫn mở, danh sách không tải lại', async () => {
        api.deleteUser.mockRejectedValue(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const { wrapper } = await mountList();

        await byTestId(wrapper, 'user-row-1').find('[data-testid="action-delete"]').trigger('click');
        await byTestId(wrapper, 'confirm-delete').trigger('click');
        await flush();

        expect(byTestId(wrapper, 'modal-error').text()).toContain('permission');
        expect(api.listUsers).toHaveBeenCalledTimes(1);
    });

    // ---- đổi trạng thái (UMS-032) & reset mật khẩu (UMS-033) ----
    it('đổi trạng thái: chọn, xác nhận, gọi API rồi tải lại danh sách', async () => {
        api.changeUserStatus.mockResolvedValue({ message: 'User status updated successfully.' });
        const { wrapper } = await mountList();

        await byTestId(wrapper, 'user-row-1').find('[data-testid="action-status"]').trigger('click');
        await byTestId(wrapper, 'status-blocked').setValue(true);

        expect(byTestId(wrapper, 'status-confirmation').text()).toBe('Are you sure you want to block this user?');

        await byTestId(wrapper, 'confirm-status').trigger('click');
        await flush();

        expect(api.changeUserStatus).toHaveBeenCalledWith(1, 'blocked');
        expect(api.listUsers).toHaveBeenCalledTimes(2);
    });

    it('reset mật khẩu từ danh sách', async () => {
        api.resetUserPassword.mockResolvedValue({ message: 'Password reset successfully.' });
        const { wrapper } = await mountList();

        await byTestId(wrapper, 'user-row-1').find('[data-testid="action-reset"]').trigger('click');
        await byTestId(wrapper, 'reset-password').setValue('NewPassword@123');
        await byTestId(wrapper, 'reset-password-confirmation').setValue('NewPassword@123');
        await wrapper.find('[role="dialog"] form').trigger('submit');
        await flush();

        expect(api.resetUserPassword).toHaveBeenCalledWith(1, { password: 'NewPassword@123', password_confirmation: 'NewPassword@123' });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// UMS-028: TẠO USER
// ---------------------------------------------------------------------------
describe('UserCreatePage (UMS-028)', () => {
    const mountCreate = (options = {}) => mountAt(UserCreatePage, { path: '/users/create', ...options });

    async function fill(wrapper, overrides = {}) {
        const v = { name: 'Nguyen Van A', email: 'a@example.com', phone: '0900000000', password: 'Password@123', confirm: 'Password@123', ...overrides };

        await byTestId(wrapper, 'field-name').setValue(v.name);
        await byTestId(wrapper, 'field-email').setValue(v.email);
        await byTestId(wrapper, 'field-phone').setValue(v.phone);
        await byTestId(wrapper, 'field-password').setValue(v.password);
        await byTestId(wrapper, 'field-password-confirmation').setValue(v.confirm);
    }

    it('form có đủ Name, Email, Phone, Password, Confirm Password, Role, Status', async () => {
        const { wrapper } = await mountCreate();

        for (const id of ['field-name', 'field-email', 'field-phone', 'field-password', 'field-password-confirmation', 'field-role', 'field-status']) {
            expect(byTestId(wrapper, id).exists(), id).toBe(true);
        }
    });

    it('ADMIN chỉ chọn được role USER', async () => {
        const { wrapper } = await mountCreate({ role: 'admin' });

        expect(byTestId(wrapper, 'field-role').findAll('option').map((o) => o.text())).toEqual(['User']);
    });

    it('SUPERADMIN chọn được ADMIN hoặc USER, không có SUPERADMIN', async () => {
        const { wrapper } = await mountCreate({ role: 'superadmin' });

        expect(byTestId(wrapper, 'field-role').findAll('option').map((o) => o.text())).toEqual(['Admin', 'User']);
    });

    it('validate phía client: không gọi API khi dữ liệu sai', async () => {
        const { wrapper } = await mountCreate();

        await wrapper.find('form').trigger('submit');

        expect(api.createUser).not.toHaveBeenCalled();
        expect(wrapper.findAll('[data-testid="field-error"]').length).toBeGreaterThanOrEqual(4);
    });

    it('thành công: gọi API đúng payload, hiện thông báo và chuyển về danh sách', async () => {
        api.createUser.mockResolvedValue({ success: true, message: 'User created successfully.', data: makeUser() });
        const { wrapper, router } = await mountCreate({ role: 'superadmin' });

        await fill(wrapper);
        await byTestId(wrapper, 'field-role').setValue('admin');
        await wrapper.find('form').trigger('submit');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('users'));
        expect(api.createUser).toHaveBeenCalledWith({
            name: 'Nguyen Van A', email: 'a@example.com', phone: '0900000000', role: 'admin', status: 'active',
            password: 'Password@123', password_confirmation: 'Password@123',
        });
        expect(useToastStore().toasts.map((t) => t.message)).toContain('User created successfully.');
    });

    it('nút submit có trạng thái loading khi đang gửi', async () => {
        api.createUser.mockReturnValue(deferred().promise);
        const { wrapper } = await mountCreate();

        await fill(wrapper);
        await wrapper.find('form').trigger('submit');

        expect(byTestId(wrapper, 'form-submit').attributes('disabled')).toBeDefined();
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(true);
    });

    it('email trùng (422): hiện đúng dưới ô Email, ở lại trang và giữ nguyên dữ liệu đã nhập', async () => {
        api.createUser.mockRejectedValue(validationError({ email: ['The email has already been taken.'] }));
        const { wrapper, router } = await mountCreate();

        await fill(wrapper, { email: 'trung@example.com' });
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(wrapper.find('#user-email-error').text()).toBe('The email has already been taken.');
        expect(router.currentRoute.value.name).toBe('user-create');
        expect(byTestId(wrapper, 'field-name').element.value).toBe('Nguyen Van A');
        expect(byTestId(wrapper, 'field-email').element.value).toBe('trung@example.com');
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(false);
    });

    it('lỗi 403 hiện thông báo ở đầu form', async () => {
        api.createUser.mockRejectedValue(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const { wrapper } = await mountCreate();

        await fill(wrapper);
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(byTestId(wrapper, 'form-error').text()).toContain('permission');
    });

    it('lỗi server (500) không lộ nội dung nội bộ', async () => {
        api.createUser.mockRejectedValue(apiError(500, { message: 'SQLSTATE[HY000] chi tiet noi bo' }));
        const { wrapper } = await mountCreate();

        await fill(wrapper);
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(byTestId(wrapper, 'form-error').text()).toBe('Server error. Please try again later.');
        expect(wrapper.html()).not.toContain('SQLSTATE');
    });

    it('nút Cancel và liên kết quay lại danh sách', async () => {
        const { wrapper, router } = await mountCreate();

        expect(wrapper.text()).toContain('← Back to users');

        await byTestId(wrapper, 'form-cancel').trigger('click');
        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('users'));
    });
});

// ---------------------------------------------------------------------------
// UMS-029: CHI TIẾT USER
// ---------------------------------------------------------------------------
describe('UserDetailPage (UMS-029)', () => {
    const target = makeUser({ id: 5, name: 'Tran Thi B', email: 'b@example.com', phone: '0911111111', role: 'user', status: 'inactive' });

    const mountDetail = (options = {}) => mountAt(UserDetailPage, { path: '/users/5', ...options });

    it('gọi API theo id trên URL và hiển thị đủ thông tin', async () => {
        api.getUser.mockResolvedValue(target);
        const { wrapper } = await mountDetail();

        expect(api.getUser).toHaveBeenCalledWith('5');
        expect(byTestId(wrapper, 'detail-name').text()).toBe('Tran Thi B');
        expect(byTestId(wrapper, 'detail-email').text()).toBe('b@example.com');
        expect(byTestId(wrapper, 'detail-phone').text()).toBe('0911111111');
        expect(wrapper.text()).toContain('User');
        expect(wrapper.text()).toContain('Inactive');
        expect(wrapper.text()).toContain('Created At');
        expect(wrapper.text()).toContain('Last Login');
    });

    it('không có avatar thì hiện chữ cái đầu, có avatar thì hiện ảnh', async () => {
        api.getUser.mockResolvedValue(target);
        const noAvatar = await mountDetail();

        expect(byTestId(noAvatar.wrapper, 'avatar-initials').text()).toBe('TT');

        api.getUser.mockResolvedValue({ ...target, avatar: 'http://x/storage/avatars/a.png' });
        const withAvatar = await mountDetail();

        expect(withAvatar.wrapper.find('img').attributes('src')).toBe('http://x/storage/avatars/a.png');
    });

    it('phone hoặc last login trống thì hiện dấu gạch', async () => {
        api.getUser.mockResolvedValue({ ...target, phone: null, last_login_at: null });
        const { wrapper } = await mountDetail();

        expect(byTestId(wrapper, 'detail-phone').text()).toBe('—');
        expect(byTestId(wrapper, 'detail-last-login').text()).toBe('—');
    });

    it('có trạng thái loading', async () => {
        api.getUser.mockReturnValue(deferred().promise);
        const { wrapper } = await mountDetail();

        expect(byTestId(wrapper, 'state-loading').exists()).toBe(true);
    });

    it('404: hiện trạng thái không tìm thấy', async () => {
        api.getUser.mockRejectedValue(apiError(404, { message: 'User not found.' }));
        const { wrapper } = await mountDetail();

        expect(byTestId(wrapper, 'user-not-found').text()).toContain('User not found.');
        expect(byTestId(wrapper, 'detail-name').exists()).toBe(false);
    });

    it('403: hiện thông báo không có quyền xem', async () => {
        api.getUser.mockRejectedValue(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const { wrapper } = await mountDetail();

        expect(byTestId(wrapper, 'user-forbidden').text()).toContain('do not have permission');
    });

    it('lỗi khác hiện trạng thái lỗi kèm nút thử lại', async () => {
        api.getUser.mockRejectedValueOnce(apiError(500)).mockResolvedValueOnce(target);
        const { wrapper } = await mountDetail();

        expect(byTestId(wrapper, 'state-error').exists()).toBe(true);

        await byTestId(wrapper, 'retry-button').trigger('click');
        await flush();

        expect(byTestId(wrapper, 'detail-name').exists()).toBe(true);
    });

    it('các nút hành động ẩn khi không có quyền (ẩn trên UI, API vẫn tự bảo vệ)', async () => {
        api.getUser.mockResolvedValue({ ...target, abilities: NO_ABILITIES });
        const { wrapper } = await mountDetail();

        for (const id of ['action-edit', 'action-reset', 'action-status', 'action-delete']) {
            expect(byTestId(wrapper, id).exists(), id).toBe(false);
        }
    });

    it('hiện đủ Edit, Reset Password, Change Status, Delete khi có quyền', async () => {
        api.getUser.mockResolvedValue(target);
        const { wrapper } = await mountDetail();

        expect(byTestId(wrapper, 'action-edit').attributes('href')).toBe('/users/5/edit');
        expect(byTestId(wrapper, 'action-reset').exists()).toBe(true);
        expect(byTestId(wrapper, 'action-status').exists()).toBe(true);
        expect(byTestId(wrapper, 'action-delete').exists()).toBe(true);
    });

    it('xóa từ trang chi tiết: xác nhận rồi quay về danh sách', async () => {
        api.getUser.mockResolvedValue(target);
        api.deleteUser.mockResolvedValue({ message: 'User deleted successfully.' });
        const { wrapper, router } = await mountDetail();

        await byTestId(wrapper, 'action-delete').trigger('click');
        await byTestId(wrapper, 'confirm-delete').trigger('click');

        await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('users'));
        expect(api.deleteUser).toHaveBeenCalledWith(5);
    });

    it('đổi trạng thái từ trang chi tiết: tải lại dữ liệu mới', async () => {
        api.getUser.mockResolvedValueOnce(target).mockResolvedValueOnce({ ...target, status: 'blocked' });
        api.changeUserStatus.mockResolvedValue({ message: 'ok' });
        const { wrapper } = await mountDetail();

        await byTestId(wrapper, 'action-status').trigger('click');
        await byTestId(wrapper, 'status-blocked').setValue(true);
        await byTestId(wrapper, 'confirm-status').trigger('click');
        await flush();

        expect(api.getUser).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('Blocked');
    });

    it('reset mật khẩu từ trang chi tiết', async () => {
        api.getUser.mockResolvedValue(target);
        api.resetUserPassword.mockResolvedValue({ message: 'ok' });
        const { wrapper } = await mountDetail();

        await byTestId(wrapper, 'action-reset').trigger('click');
        await byTestId(wrapper, 'reset-password').setValue('NewPassword@123');
        await byTestId(wrapper, 'reset-password-confirmation').setValue('NewPassword@123');
        await wrapper.find('[role="dialog"] form').trigger('submit');
        await flush();

        expect(api.resetUserPassword).toHaveBeenCalledWith(5, { password: 'NewPassword@123', password_confirmation: 'NewPassword@123' });
    });

    it('tên chứa HTML được hiển thị dạng chữ', async () => {
        api.getUser.mockResolvedValue({ ...target, name: '<img src=x onerror=alert(1)>', avatar: null });
        const { wrapper } = await mountDetail();

        expect(wrapper.find('img').exists()).toBe(false);
        expect(byTestId(wrapper, 'detail-name').text()).toContain('<img');
    });
});

// ---------------------------------------------------------------------------
// UMS-030: SỬA USER
// ---------------------------------------------------------------------------
describe('UserEditPage (UMS-030)', () => {
    const target = makeUser({ id: 5, name: 'Tran Thi B', email: 'b@example.com', phone: '0911111111', role: 'user', status: 'active' });

    const mountEdit = (options = {}) => mountAt(UserEditPage, { path: '/users/5/edit', ...options });

    beforeEach(() => {
        api.getUser.mockResolvedValue(target);
    });

    it('form được điền sẵn dữ liệu hiện tại', async () => {
        const { wrapper } = await mountEdit();

        expect(api.getUser).toHaveBeenCalledWith('5');
        expect(byTestId(wrapper, 'field-name').element.value).toBe('Tran Thi B');
        expect(byTestId(wrapper, 'field-email').element.value).toBe('b@example.com');
        expect(byTestId(wrapper, 'field-phone').element.value).toBe('0911111111');
        expect(byTestId(wrapper, 'field-role').element.value).toBe('user');
        expect(byTestId(wrapper, 'field-status').element.value).toBe('active');
    });

    it('không có ô mật khẩu', async () => {
        const { wrapper } = await mountEdit();

        expect(byTestId(wrapper, 'field-password').exists()).toBe(false);
    });

    it('ADMIN chỉ thấy role USER (không thể nâng USER lên ADMIN/SUPERADMIN)', async () => {
        const { wrapper } = await mountEdit({ role: 'admin' });

        expect(byTestId(wrapper, 'field-role').findAll('option').map((o) => o.text())).toEqual(['User']);
    });

    it('SUPERADMIN chọn được ADMIN và USER', async () => {
        const { wrapper } = await mountEdit({ role: 'superadmin' });

        expect(byTestId(wrapper, 'field-role').findAll('option').map((o) => o.text())).toEqual(['Admin', 'User']);
    });

    it('validate phía client', async () => {
        const { wrapper } = await mountEdit();

        await byTestId(wrapper, 'field-name').setValue('');
        await byTestId(wrapper, 'field-email').setValue('sai');
        await wrapper.find('form').trigger('submit');

        expect(api.updateUser).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Name is required.');
        expect(wrapper.text()).toContain('valid email');
    });

    it('lưu thành công: gọi PUT, hiện thông báo và cập nhật form theo dữ liệu server trả về', async () => {
        api.updateUser.mockResolvedValue({ success: true, message: 'User updated successfully.', data: { ...target, name: 'Ten Moi Tu Server' } });
        const { wrapper } = await mountEdit();

        await byTestId(wrapper, 'field-name').setValue('Ten Moi');
        await byTestId(wrapper, 'field-status').setValue('blocked');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(api.updateUser).toHaveBeenCalledWith(5, { name: 'Ten Moi', email: 'b@example.com', phone: '0911111111', role: 'user', status: 'blocked' });
        expect(useToastStore().toasts.map((t) => t.message)).toContain('User updated successfully.');
        expect(byTestId(wrapper, 'field-name').element.value).toBe('Ten Moi Tu Server');
    });

    it('payload sửa user không bao giờ chứa mật khẩu', async () => {
        api.updateUser.mockResolvedValue({ message: 'ok', data: target });
        const { wrapper } = await mountEdit();

        await wrapper.find('form').trigger('submit');
        await flush();

        expect(Object.keys(api.updateUser.mock.calls[0][1])).not.toContain('password');
    });

    it('email trùng (422) hiện dưới ô Email', async () => {
        api.updateUser.mockRejectedValue(validationError({ email: ['The email has already been taken.'] }));
        const { wrapper } = await mountEdit();

        await byTestId(wrapper, 'field-email').setValue('trung@example.com');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(wrapper.find('#user-email-error').text()).toBe('The email has already been taken.');
    });

    it('lỗi 403 khi lưu hiện thông báo API', async () => {
        api.updateUser.mockRejectedValue(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const { wrapper } = await mountEdit();

        await wrapper.find('form').trigger('submit');
        await flush();

        expect(byTestId(wrapper, 'form-error').text()).toContain('permission');
    });

    it('nút lưu có loading khi đang gửi', async () => {
        api.updateUser.mockReturnValue(deferred().promise);
        const { wrapper } = await mountEdit();

        await wrapper.find('form').trigger('submit');

        expect(byTestId(wrapper, 'form-submit').attributes('disabled')).toBeDefined();
    });

    it('không có quyền sửa (abilities.update = false) thì không hiển thị form', async () => {
        api.getUser.mockResolvedValue({ ...target, role: 'admin', abilities: NO_ABILITIES });
        const { wrapper } = await mountEdit({ role: 'admin' });

        expect(byTestId(wrapper, 'user-forbidden').text()).toContain('do not have permission');
        expect(byTestId(wrapper, 'user-form').exists()).toBe(false);
    });

    it('404 và 403 từ API', async () => {
        api.getUser.mockRejectedValueOnce(apiError(404, { message: 'User not found.' }));
        const notFound = await mountEdit();

        expect(byTestId(notFound.wrapper, 'user-not-found').exists()).toBe(true);

        api.getUser.mockRejectedValueOnce(apiError(403, { message: 'nope' }));
        const forbidden = await mountEdit();

        expect(byTestId(forbidden.wrapper, 'user-forbidden').exists()).toBe(true);
    });

    it('có trạng thái loading', async () => {
        api.getUser.mockReturnValue(deferred().promise);
        const { wrapper } = await mountEdit();

        expect(byTestId(wrapper, 'state-loading').exists()).toBe(true);
        expect(byTestId(wrapper, 'user-form').exists()).toBe(false);
    });
});
