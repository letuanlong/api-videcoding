import * as api from '../api';
import UserForm from './UserForm.vue';
import PaginationBar from './PaginationBar.vue';
import ChangePasswordForm from './ChangePasswordForm.vue';
import StateBox from './StateBox.vue';
import { apiError, byTestId, deferred, flush, makeMeta, mountAt, validationError } from '../test/helpers';

vi.mock('../api');

beforeEach(() => vi.clearAllMocks());

const mountForm = (props = {}) => mountAt(UserForm, { props: { mode: 'create', roleOptions: ['admin', 'user'], ...props } });

async function fillCreate(wrapper, values = {}) {
    const data = { name: 'Nguyen Van A', email: 'a@example.com', phone: '0900000000', password: 'Password@123', confirm: 'Password@123', ...values };

    await byTestId(wrapper, 'field-name').setValue(data.name);
    await byTestId(wrapper, 'field-email').setValue(data.email);
    await byTestId(wrapper, 'field-phone').setValue(data.phone);
    await byTestId(wrapper, 'field-password').setValue(data.password);
    await byTestId(wrapper, 'field-password-confirmation').setValue(data.confirm);
}

describe('UserForm', () => {
    it('chế độ create có ô mật khẩu, edit thì không', async () => {
        const create = await mountForm();
        const edit = await mountForm({ mode: 'edit' });

        expect(byTestId(create.wrapper, 'field-password').exists()).toBe(true);
        expect(byTestId(create.wrapper, 'field-password-confirmation').exists()).toBe(true);
        expect(byTestId(edit.wrapper, 'field-password').exists()).toBe(false);
    });

    it('dropdown role chỉ có các lựa chọn được truyền vào', async () => {
        const admin = await mountForm({ roleOptions: ['user'] });
        const superadmin = await mountForm({ roleOptions: ['admin', 'user'] });
        const options = (w) => byTestId(w.wrapper, 'field-role').findAll('option').map((o) => o.text());

        expect(options(admin)).toEqual(['User']);
        expect(options(superadmin)).toEqual(['Admin', 'User']);
        expect(options(superadmin)).not.toContain('Super Admin');
    });

    it('dropdown status có Active, Inactive, Blocked và mặc định Active', async () => {
        const { wrapper } = await mountForm();

        expect(byTestId(wrapper, 'field-status').findAll('option').map((o) => o.text())).toEqual(['Active', 'Inactive', 'Blocked']);
        expect(byTestId(wrapper, 'field-status').element.value).toBe('active');
    });

    it('form trống không gửi đi và hiện lỗi từng ô', async () => {
        const { wrapper } = await mountForm();

        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.text()).toContain('Name is required.');
        expect(wrapper.text()).toContain('Email is required.');
        expect(wrapper.text()).toContain('Password is required.');
    });

    it('email sai định dạng, mật khẩu yếu, xác nhận không khớp đều bị chặn ở client', async () => {
        const { wrapper } = await mountForm();

        await fillCreate(wrapper, { email: 'khong-phai-email', password: 'weak', confirm: 'khac' });
        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.text()).toContain('valid email');
        expect(wrapper.text()).toContain('at least 8 characters');
        expect(wrapper.text()).toContain('does not match');
    });

    it('dữ liệu hợp lệ phát sự kiện submit với payload đã trim', async () => {
        const { wrapper } = await mountForm();

        await fillCreate(wrapper, { name: '  Nguyen Van A  ', email: '  a@example.com ' });
        await byTestId(wrapper, 'field-role').setValue('admin');
        await byTestId(wrapper, 'field-status').setValue('inactive');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('submit')[0][0]).toEqual({
            name: 'Nguyen Van A',
            email: 'a@example.com',
            phone: '0900000000',
            role: 'admin',
            status: 'inactive',
            password: 'Password@123',
            password_confirmation: 'Password@123',
        });
    });

    it('chế độ edit không gửi trường mật khẩu', async () => {
        const { wrapper } = await mountForm({ mode: 'edit', roleOptions: ['user'], initial: { name: 'A', email: 'a@example.com', phone: '', role: 'user', status: 'active' } });

        await wrapper.find('form').trigger('submit');

        expect(Object.keys(wrapper.emitted('submit')[0][0]).sort()).toEqual(['email', 'name', 'phone', 'role', 'status']);
    });

    it('edit: điền sẵn dữ liệu hiện tại', async () => {
        const { wrapper } = await mountForm({ mode: 'edit', initial: { name: 'Tran B', email: 'b@example.com', phone: '0911', role: 'admin', status: 'blocked' } });

        expect(byTestId(wrapper, 'field-name').element.value).toBe('Tran B');
        expect(byTestId(wrapper, 'field-email').element.value).toBe('b@example.com');
        expect(byTestId(wrapper, 'field-phone').element.value).toBe('0911');
        expect(byTestId(wrapper, 'field-role').element.value).toBe('admin');
        expect(byTestId(wrapper, 'field-status').element.value).toBe('blocked');
    });

    it('edit: điền lại form khi dữ liệu mới được truyền vào (sau khi lưu)', async () => {
        const { wrapper } = await mountForm({ mode: 'edit', initial: { name: 'Cu', email: 'a@example.com', role: 'user', status: 'active' } });

        await wrapper.setProps({ initial: { name: 'Moi', email: 'a@example.com', role: 'user', status: 'active' } });

        expect(byTestId(wrapper, 'field-name').element.value).toBe('Moi');
    });

    it('hiển thị lỗi từ server dưới đúng ô (vd. email trùng)', async () => {
        const { wrapper } = await mountForm();

        await wrapper.setProps({ serverErrors: { email: 'The email has already been taken.' } });

        expect(byTestId(wrapper, 'field-email').attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('#user-email-error').text()).toBe('The email has already been taken.');
    });

    it('sửa ô nào thì lỗi server của ô đó biến mất', async () => {
        const { wrapper } = await mountForm();

        await wrapper.setProps({ serverErrors: { email: 'The email has already been taken.', name: 'Bad name' } });
        await byTestId(wrapper, 'field-email').setValue('other@example.com');

        expect(wrapper.find('#user-email-error').exists()).toBe(false);
        expect(wrapper.find('#user-name-error').exists()).toBe(true);
    });

    it('đang gửi thì nút có loading và bị khóa', async () => {
        const { wrapper } = await mountForm({ submitting: true });

        expect(byTestId(wrapper, 'form-submit').attributes('disabled')).toBeDefined();
        expect(byTestId(wrapper, 'button-spinner').exists()).toBe(true);
        expect(byTestId(wrapper, 'form-cancel').attributes('disabled')).toBeDefined();
    });

    it('giữ nguyên dữ liệu (kể cả mật khẩu) sau khi gửi để lỗi từ server chỉ phải sửa đúng ô bị lỗi', async () => {
        const { wrapper } = await mountForm();

        await fillCreate(wrapper);
        await wrapper.find('form').trigger('submit');

        expect(byTestId(wrapper, 'field-password').element.value).toBe('Password@123');
        expect(byTestId(wrapper, 'field-password-confirmation').element.value).toBe('Password@123');
        expect(byTestId(wrapper, 'field-email').element.value).toBe('a@example.com');
    });

    it('sau lỗi email trùng từ server, sửa email rồi gửi lại được ngay mà không phải nhập lại mật khẩu', async () => {
        const { wrapper } = await mountForm();

        await fillCreate(wrapper, { email: 'trung@example.com' });
        await wrapper.find('form').trigger('submit');
        await wrapper.setProps({ serverErrors: { email: 'The email has already been taken.' } });

        await byTestId(wrapper, 'field-email').setValue('moi@example.com');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('submit')).toHaveLength(2);
        expect(wrapper.emitted('submit')[1][0]).toMatchObject({ email: 'moi@example.com', password: 'Password@123' });
    });

    it('ô mật khẩu là kiểu password', async () => {
        const { wrapper } = await mountForm();

        expect(byTestId(wrapper, 'field-password').attributes('type')).toBe('password');
    });

    it('nút Cancel phát sự kiện cancel', async () => {
        const { wrapper } = await mountForm();

        await byTestId(wrapper, 'form-cancel').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});

describe('PaginationBar', () => {
    const mountBar = (meta) => mountAt(PaginationBar, { props: { meta: makeMeta(meta) } });

    it('hiển thị khoảng đang xem', async () => {
        const { wrapper } = await mountBar({ current_page: 2, per_page: 20, total: 45, last_page: 3 });

        expect(byTestId(wrapper, 'pagination-summary').text()).toBe('Showing 21–40 of 45');
    });

    it('trang cuối hiển thị đúng số còn lại, trống thì Showing 0–0 of 0', async () => {
        expect((await mountBar({ current_page: 3, per_page: 20, total: 45, last_page: 3 })).wrapper.text()).toContain('Showing 41–45 of 45');
        expect((await mountBar({ total: 0 })).wrapper.text()).toContain('Showing 0–0 of 0');
    });

    it('trang đầu khóa Previous, trang cuối khóa Next', async () => {
        const first = await mountBar({ current_page: 1, last_page: 3, total: 50 });
        const last = await mountBar({ current_page: 3, last_page: 3, total: 50 });

        expect(byTestId(first.wrapper, 'page-prev').attributes('disabled')).toBeDefined();
        expect(byTestId(first.wrapper, 'page-next').attributes('disabled')).toBeUndefined();
        expect(byTestId(last.wrapper, 'page-next').attributes('disabled')).toBeDefined();
    });

    it('bấm số trang, Next, Previous phát sự kiện page', async () => {
        const { wrapper } = await mountBar({ current_page: 2, last_page: 4, total: 80 });

        await byTestId(wrapper, 'page-3').trigger('click');
        await byTestId(wrapper, 'page-next').trigger('click');
        await byTestId(wrapper, 'page-prev').trigger('click');

        expect(wrapper.emitted('page').map((e) => e[0])).toEqual([3, 3, 1]);
    });

    it('trang hiện tại được đánh dấu aria-current', async () => {
        const { wrapper } = await mountBar({ current_page: 2, last_page: 3, total: 50 });

        expect(byTestId(wrapper, 'page-2').attributes('aria-current')).toBe('page');
        expect(byTestId(wrapper, 'page-1').attributes('aria-current')).toBeUndefined();
    });

    it('chỉ hiển thị tối đa 5 số trang quanh trang hiện tại', async () => {
        const { wrapper } = await mountBar({ current_page: 10, last_page: 20, total: 400 });
        const pages = wrapper.findAll('[data-testid^="page-"]').map((b) => b.attributes('data-testid')).filter((id) => /page-\d+/.test(id));

        expect(pages).toEqual(['page-8', 'page-9', 'page-10', 'page-11', 'page-12']);
    });

    it('cửa sổ số trang không vượt biên ở đầu và cuối', async () => {
        const start = await mountBar({ current_page: 1, last_page: 20, total: 400 });
        const end = await mountBar({ current_page: 20, last_page: 20, total: 400 });
        const numbers = (w) => w.findAll('[data-testid^="page-"]').map((b) => b.attributes('data-testid')).filter((id) => /page-\d+/.test(id));

        expect(numbers(start.wrapper)).toEqual(['page-1', 'page-2', 'page-3', 'page-4', 'page-5']);
        expect(numbers(end.wrapper)).toEqual(['page-16', 'page-17', 'page-18', 'page-19', 'page-20']);
    });

    it('chọn số bản ghi mỗi trang phát sự kiện per-page dạng số', async () => {
        const { wrapper } = await mountBar({ per_page: 20 });

        expect(byTestId(wrapper, 'per-page-select').findAll('option').map((o) => o.text())).toEqual(['10', '20', '50', '100']);

        await byTestId(wrapper, 'per-page-select').setValue('50');

        expect(wrapper.emitted('per-page')[0]).toEqual([50]);
    });
});

describe('StateBox', () => {
    it('loading hiển thị thông báo mặc định', async () => {
        const { wrapper } = await mountAt(StateBox, { props: { kind: 'loading' } });

        expect(wrapper.text()).toContain('Loading…');
        expect(wrapper.find('[role="status"]').exists()).toBe(true);
    });

    it('error có nút thử lại phát sự kiện retry', async () => {
        const { wrapper } = await mountAt(StateBox, { props: { kind: 'error', message: 'Boom' } });

        expect(wrapper.find('[role="alert"]').text()).toBe('Boom');

        await byTestId(wrapper, 'retry-button').trigger('click');

        expect(wrapper.emitted('retry')).toHaveLength(1);
    });

    it('empty hiển thị thông báo và cho phép chèn nội dung thêm', async () => {
        const { wrapper } = await mountAt(StateBox, { props: { kind: 'empty', message: 'No users found.' }, attachTo: document.body });

        expect(wrapper.text()).toContain('No users found.');
    });
});

describe('ChangePasswordForm (UMS-021 / 034)', () => {
    const mountForm = () => mountAt(ChangePasswordForm);

    async function fill(wrapper, current, next, confirm) {
        await byTestId(wrapper, 'cp-current').setValue(current);
        await byTestId(wrapper, 'cp-new').setValue(next);
        await byTestId(wrapper, 'cp-confirm').setValue(confirm);
    }

    it('form trống báo lỗi cả ba ô và không gọi API', async () => {
        const { wrapper } = await mountForm();

        await wrapper.find('form').trigger('submit');

        expect(wrapper.findAll('[data-testid="field-error"]')).toHaveLength(3);
        expect(api.changePassword).not.toHaveBeenCalled();
    });

    it('mật khẩu mới không được trùng mật khẩu hiện tại', async () => {
        const { wrapper } = await mountForm();

        await fill(wrapper, 'Password@123', 'Password@123', 'Password@123');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('must be different from the current password');
        expect(api.changePassword).not.toHaveBeenCalled();
    });

    it('mật khẩu mới yếu hoặc xác nhận không khớp bị chặn', async () => {
        const { wrapper } = await mountForm();

        await fill(wrapper, 'Old@12345', 'weak', 'khac');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('at least 8 characters');
        expect(wrapper.text()).toContain('does not match');
    });

    it('thành công: gọi API đúng tên trường và phát changed', async () => {
        api.changePassword.mockResolvedValue({ success: true });
        const { wrapper } = await mountForm();

        await fill(wrapper, 'OldPassword@123', 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(api.changePassword).toHaveBeenCalledWith({
            current_password: 'OldPassword@123',
            password: 'NewPassword@123',
            password_confirmation: 'NewPassword@123',
        });
        expect(wrapper.emitted('changed')).toHaveLength(1);
    });

    it('mật khẩu hiện tại sai (422) hiện ngay dưới ô current password', async () => {
        api.changePassword.mockRejectedValue(validationError({ current_password: ['The current password is incorrect.'] }));
        const { wrapper } = await mountForm();

        await fill(wrapper, 'Sai@123456', 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(wrapper.find('#current-password-error').text()).toBe('The current password is incorrect.');
        expect(wrapper.emitted('changed')).toBeUndefined();
        // Mật khẩu hiện tại sai thì xóa để nhập lại, còn mật khẩu mới giữ nguyên
        expect(byTestId(wrapper, 'cp-current').element.value).toBe('');
        expect(byTestId(wrapper, 'cp-new').element.value).toBe('NewPassword@123');
        expect(byTestId(wrapper, 'cp-confirm').element.value).toBe('NewPassword@123');
    });

    it('lỗi chung (429) hiện thông báo ở đầu form', async () => {
        api.changePassword.mockRejectedValue(apiError(429, { message: 'Too Many Attempts.' }));
        const { wrapper } = await mountForm();

        await fill(wrapper, 'OldPassword@123', 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');
        await flush();

        expect(byTestId(wrapper, 'form-error').text()).toMatch(/Too many attempts/);
    });

    it('nút có loading khi đang gửi', async () => {
        api.changePassword.mockReturnValue(deferred().promise);
        const { wrapper } = await mountForm();

        await fill(wrapper, 'OldPassword@123', 'NewPassword@123', 'NewPassword@123');
        await wrapper.find('form').trigger('submit');

        expect(byTestId(wrapper, 'cp-submit').attributes('disabled')).toBeDefined();
    });
});
