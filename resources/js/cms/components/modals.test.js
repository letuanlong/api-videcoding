import * as api from '../api';
import DeleteUserModal from './DeleteUserModal.vue';
import ChangeStatusModal from './ChangeStatusModal.vue';
import ResetPasswordModal from './ResetPasswordModal.vue';
import { useToastStore } from '../stores/toast';
import { apiError, byTestId, deferred, flush, makeUser, mountAt, networkError, validationError } from '../test/helpers';

vi.mock('../api');

const user = makeUser({ id: 7, name: 'Nguyen Van A', status: 'active' });

beforeEach(() => vi.clearAllMocks());

describe('DeleteUserModal (UMS-031)', () => {
    const mountModal = () => mountAt(DeleteUserModal, { props: { user } });

    it('hiển thị xác nhận với tên người sắp bị xóa', async () => {
        const { wrapper } = await mountModal();

        expect(wrapper.text()).toContain('Are you sure?');
        expect(wrapper.text()).toContain('You are about to delete:');
        expect(byTestId(wrapper, 'delete-target').text()).toBe('"Nguyen Van A"');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    });

    it('bấm Cancel chỉ đóng, không gọi API', async () => {
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'cancel-button').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(api.deleteUser).not.toHaveBeenCalled();
    });

    it('bấm Delete gọi API, hiện thông báo thành công và báo hoàn tất', async () => {
        api.deleteUser.mockResolvedValue({ success: true, message: 'User deleted successfully.' });
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'confirm-delete').trigger('click');
        await flush();

        expect(api.deleteUser).toHaveBeenCalledWith(7);
        expect(wrapper.emitted('done')).toHaveLength(1);
        expect(useToastStore().toasts.map((t) => t.message)).toContain('User deleted successfully.');
    });

    it('nút Delete có trạng thái loading và không cho bấm lại khi đang gửi', async () => {
        const pending = deferred();
        api.deleteUser.mockReturnValue(pending.promise);
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'confirm-delete').trigger('click');

        expect(byTestId(wrapper, 'confirm-delete').attributes('disabled')).toBeDefined();
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(true);
        expect(byTestId(wrapper, 'cancel-button').attributes('disabled')).toBeDefined();

        await byTestId(wrapper, 'confirm-delete').trigger('click');
        expect(api.deleteUser).toHaveBeenCalledTimes(1);

        pending.resolve({ message: 'ok' });
        await flush();
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(false);
    });

    it('đang gửi thì Esc và bấm nền không đóng được hộp thoại', async () => {
        api.deleteUser.mockReturnValue(deferred().promise);
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'confirm-delete').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await byTestId(wrapper, 'modal-backdrop').trigger('mousedown');

        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('lúc rảnh thì Esc và bấm nền đóng được', async () => {
        const { wrapper } = await mountModal();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await byTestId(wrapper, 'modal-backdrop').trigger('mousedown');

        expect(wrapper.emitted('close')).toHaveLength(2);
    });

    it.each([
        ['403', apiError(403, { message: 'You do not have permission to perform this action.' }), 'You do not have permission to perform this action.'],
        ['404', apiError(404, { message: 'User not found.' }), 'User not found.'],
        ['500', apiError(500, { message: 'SQLSTATE nội bộ' }), 'Server error. Please try again later.'],
        ['mạng', networkError(), 'Cannot reach the server. Please check your connection and try again.'],
    ])('lỗi %s: hiện thông báo trong hộp thoại, không đóng, không báo hoàn tất', async (_name, error, message) => {
        api.deleteUser.mockRejectedValue(error);
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'confirm-delete').trigger('click');
        await flush();

        expect(byTestId(wrapper, 'modal-error').text()).toBe(message);
        expect(wrapper.emitted('done')).toBeUndefined();
        expect(byTestId(wrapper, 'confirm-delete').attributes('disabled')).toBeUndefined();
    });

    it('tên chứa HTML được hiển thị dạng chữ, không thực thi', async () => {
        const { wrapper } = await mountAt(DeleteUserModal, { props: { user: makeUser({ name: '<img src=x onerror=alert(1)>' }) } });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(byTestId(wrapper, 'delete-target').text()).toContain('<img src=x onerror=alert(1)>');
    });
});

describe('ChangeStatusModal (UMS-032)', () => {
    const mountModal = (overrides = {}) => mountAt(ChangeStatusModal, { props: { user: { ...user, ...overrides } } });

    it('liệt kê 3 trạng thái và chọn sẵn trạng thái hiện tại', async () => {
        const { wrapper } = await mountModal({ status: 'inactive' });

        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(3);
        expect(byTestId(wrapper, 'status-inactive').element.checked).toBe(true);
    });

    it('chưa đổi gì thì chưa có câu xác nhận và nút Confirm bị khóa', async () => {
        const { wrapper } = await mountModal();

        expect(byTestId(wrapper, 'status-confirmation').exists()).toBe(false);
        expect(byTestId(wrapper, 'confirm-status').attributes('disabled')).toBeDefined();
    });

    it('chọn trạng thái mới thì hiện câu xác nhận đúng trạng thái', async () => {
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'status-blocked').setValue(true);
        expect(byTestId(wrapper, 'status-confirmation').text()).toBe('Are you sure you want to block this user?');

        await byTestId(wrapper, 'status-inactive').setValue(true);
        expect(byTestId(wrapper, 'status-confirmation').text()).toBe('Are you sure you want to deactivate this user?');
    });

    it('Confirm gọi API với trạng thái đã chọn, báo hoàn tất và hiện toast', async () => {
        api.changeUserStatus.mockResolvedValue({ message: 'User status updated successfully.' });
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'status-blocked').setValue(true);
        await byTestId(wrapper, 'confirm-status').trigger('click');
        await flush();

        expect(api.changeUserStatus).toHaveBeenCalledWith(7, 'blocked');
        expect(wrapper.emitted('done')).toHaveLength(1);
        expect(useToastStore().toasts[0]).toMatchObject({ type: 'success', message: 'User status updated successfully.' });
    });

    it('hiện loading trong lúc gửi', async () => {
        api.changeUserStatus.mockReturnValue(deferred().promise);
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'status-blocked').setValue(true);
        await byTestId(wrapper, 'confirm-status').trigger('click');

        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(true);
        expect(byTestId(wrapper, 'status-active').attributes('disabled')).toBeDefined();
    });

    it('hiện lỗi API trong hộp thoại và không đóng', async () => {
        api.changeUserStatus.mockRejectedValue(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'status-blocked').setValue(true);
        await byTestId(wrapper, 'confirm-status').trigger('click');
        await flush();

        expect(byTestId(wrapper, 'modal-error').text()).toContain('permission');
        expect(wrapper.emitted('done')).toBeUndefined();
    });

    it('Cancel không gọi API', async () => {
        const { wrapper } = await mountModal();

        await byTestId(wrapper, 'cancel-button').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(api.changeUserStatus).not.toHaveBeenCalled();
    });
});

describe('ResetPasswordModal (UMS-033)', () => {
    const mountModal = () => mountAt(ResetPasswordModal, { props: { user } });

    async function fill(wrapper, password, confirmation) {
        await byTestId(wrapper, 'reset-password').setValue(password);
        await byTestId(wrapper, 'reset-password-confirmation').setValue(confirmation);
    }

    it('validate mật khẩu mới theo chính sách và không gọi API', async () => {
        const { wrapper } = await mountModal();

        await fill(wrapper, 'weak', 'weak');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toMatch(/at least 8 characters/);
        expect(api.resetUserPassword).not.toHaveBeenCalled();
    });

    it('xác nhận mật khẩu phải khớp', async () => {
        const { wrapper } = await mountModal();

        await fill(wrapper, 'NewPassword@123', 'Khac@12345');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('Password confirmation does not match.');
        expect(api.resetUserPassword).not.toHaveBeenCalled();
    });

    it('để trống thì báo lỗi cả hai ô', async () => {
        const { wrapper } = await mountModal();

        await wrapper.find('form').trigger('submit');

        expect(wrapper.findAll('[data-testid="field-error"]')).toHaveLength(2);
    });

    it('thành công: gọi API, hiện toast và báo hoàn tất', async () => {
        api.resetUserPassword.mockResolvedValue({ message: 'Password reset successfully.' });
        const { wrapper } = await mountModal();

        await fill(wrapper, 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(api.resetUserPassword).toHaveBeenCalledWith(7, { password: 'NewPassword@123', password_confirmation: 'NewPassword@123' });
        expect(wrapper.emitted('done')).toHaveLength(1);
        expect(useToastStore().toasts[0].message).toBe('Password reset successfully.');
    });

    it('mật khẩu không xuất hiện trong toast hay bất kỳ thông báo nào', async () => {
        api.resetUserPassword.mockResolvedValue({ message: 'Password reset successfully.' });
        const { wrapper } = await mountModal();

        await fill(wrapper, 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(JSON.stringify(useToastStore().toasts)).not.toContain('NewPassword@123');
        expect(wrapper.html()).not.toContain('NewPassword@123');
    });

    it('lỗi 422 từ server hiện dưới ô mật khẩu và giữ lại dữ liệu đã nhập để sửa', async () => {
        api.resetUserPassword.mockRejectedValue(validationError({ password: ['The password field must contain at least one symbol.'] }));
        const { wrapper } = await mountModal();

        await fill(wrapper, 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(wrapper.text()).toContain('must contain at least one symbol');
        expect(wrapper.emitted('done')).toBeUndefined();
        expect(byTestId(wrapper, 'reset-password').element.value).toBe('NewPassword@123');
    });

    it('lỗi 403 hiện thông báo chung', async () => {
        api.resetUserPassword.mockRejectedValue(apiError(403, { message: 'You do not have permission to perform this action.' }));
        const { wrapper } = await mountModal();

        await fill(wrapper, 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(byTestId(wrapper, 'modal-error').text()).toContain('permission');
    });

    it('nút có loading khi đang gửi', async () => {
        api.resetUserPassword.mockReturnValue(deferred().promise);
        const { wrapper } = await mountModal();

        await fill(wrapper, 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');

        expect(byTestId(wrapper, 'confirm-reset').attributes('disabled')).toBeDefined();
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(true);
    });

    it('ô mật khẩu là kiểu password và tắt tự điền', async () => {
        const { wrapper } = await mountModal();

        expect(byTestId(wrapper, 'reset-password').attributes('type')).toBe('password');
        expect(byTestId(wrapper, 'reset-password').attributes('autocomplete')).toBe('new-password');
    });

    it('sửa ô nào thì lỗi của ô đó biến mất', async () => {
        const { wrapper } = await mountModal();

        await wrapper.find('form').trigger('submit');
        expect(wrapper.findAll('[data-testid="field-error"]')).toHaveLength(2);

        await byTestId(wrapper, 'reset-password').setValue('a');
        expect(wrapper.findAll('[data-testid="field-error"]')).toHaveLength(1);
    });
});
