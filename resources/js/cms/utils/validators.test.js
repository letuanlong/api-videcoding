import {
    validateAvatarFile,
    validateEmail,
    validateLogin,
    validatePassword,
    validatePasswordConfirmation,
    validatePasswordPair,
    validateProfile,
    validateUserForm,
} from './validators';

describe('validateEmail', () => {
    it.each(['', '   ', undefined])('yêu cầu nhập email (%s)', (value) => {
        expect(validateEmail(value)).toBe('Email is required.');
    });

    it.each(['abc', 'a@b', '@example.com', 'a b@example.com', 'a@@example.com'])('từ chối email sai định dạng: %s', (value) => {
        expect(validateEmail(value)).toBe('Please enter a valid email address.');
    });

    it('từ chối email quá 255 ký tự', () => {
        expect(validateEmail(`${'a'.repeat(250)}@example.com`)).toMatch(/255/);
    });

    it('chấp nhận email hợp lệ và bỏ khoảng trắng hai đầu', () => {
        expect(validateEmail('user@example.com')).toBeNull();
        expect(validateEmail('  user@example.com  ')).toBeNull();
    });
});

describe('validatePassword (khớp chính sách backend)', () => {
    it.each([
        ['', /required/],
        ['Ab@1', /at least 8/],
        ['Aa@1'.padEnd(73, 'x'), /longer than 72/],
        ['password@123', /uppercase and lowercase/],
        ['PASSWORD@123', /uppercase and lowercase/],
        ['Password@abc', /number/],
        ['Password123', /symbol/],
    ])('từ chối %j', (value, pattern) => {
        expect(validatePassword(value)).toMatch(pattern);
    });

    it.each(['Password@123', 'Aa1!aaaa', `Aa@1${'x'.repeat(68)}`])('chấp nhận %s', (value) => {
        expect(validatePassword(value)).toBeNull();
    });
});

describe('validatePasswordConfirmation', () => {
    it('yêu cầu xác nhận và phải khớp', () => {
        expect(validatePasswordConfirmation('Password@123', '')).toMatch(/confirm/);
        expect(validatePasswordConfirmation('Password@123', 'Other@1234')).toMatch(/does not match/);
        expect(validatePasswordConfirmation('Password@123', 'Password@123')).toBeNull();
    });
});

describe('validateLogin', () => {
    it('báo lỗi cả hai trường khi để trống', () => {
        expect(Object.keys(validateLogin({ email: '', password: '' }))).toEqual(['email', 'password']);
    });

    it('không áp chính sách mật khẩu mạnh khi đăng nhập (user cũ có thể có mật khẩu yếu)', () => {
        expect(validateLogin({ email: 'a@example.com', password: '123' })).toEqual({});
    });
});

describe('validateUserForm', () => {
    const valid = {
        name: 'Nguyen Van A', email: 'a@example.com', phone: '0900000000', role: 'user', status: 'active',
        password: 'Password@123', password_confirmation: 'Password@123',
    };

    it('form hợp lệ không có lỗi', () => {
        expect(validateUserForm(valid, 'create')).toEqual({});
        expect(validateUserForm(valid, 'edit')).toEqual({});
    });

    it('chế độ create yêu cầu mật khẩu, chế độ edit thì bỏ qua', () => {
        const noPassword = { ...valid, password: '', password_confirmation: '' };

        expect(Object.keys(validateUserForm(noPassword, 'create'))).toEqual(['password', 'password_confirmation']);
        expect(validateUserForm(noPassword, 'edit')).toEqual({});
    });

    it('kiểm tra tên, email, phone, role, status', () => {
        const errors = validateUserForm({ ...valid, name: '', email: 'x', phone: '1'.repeat(21), role: '', status: '' }, 'edit');

        expect(Object.keys(errors).sort()).toEqual(['email', 'name', 'phone', 'role', 'status']);
    });

    it('phone là tùy chọn', () => {
        expect(validateUserForm({ ...valid, phone: '' }, 'edit')).toEqual({});
    });
});

describe('validatePasswordPair và validateProfile', () => {
    it('validatePasswordPair kiểm tra mật khẩu mới và xác nhận', () => {
        expect(validatePasswordPair({ password: 'weak', password_confirmation: 'weak' })).toHaveProperty('password');
        expect(validatePasswordPair({ password: 'Password@123', password_confirmation: 'x' })).toHaveProperty('password_confirmation');
        expect(validatePasswordPair({ password: 'Password@123', password_confirmation: 'Password@123' })).toEqual({});
    });

    it('validateProfile yêu cầu tên', () => {
        expect(validateProfile({ name: '', phone: '' })).toHaveProperty('name');
        expect(validateProfile({ name: 'A', phone: '' })).toEqual({});
    });
});

describe('validateAvatarFile', () => {
    const file = (type, size) => ({ type, size });

    it('không chọn file thì hợp lệ', () => {
        expect(validateAvatarFile(null)).toBeNull();
    });

    it.each(['image/jpeg', 'image/png', 'image/webp'])('chấp nhận %s', (type) => {
        expect(validateAvatarFile(file(type, 1024))).toBeNull();
    });

    it.each(['image/svg+xml', 'application/pdf', 'text/html', 'image/gif'])('từ chối %s', (type) => {
        expect(validateAvatarFile(file(type, 1024))).toMatch(/JPG, PNG or WebP/);
    });

    it('từ chối file lớn hơn 2 MB, chấp nhận đúng 2 MB', () => {
        expect(validateAvatarFile(file('image/png', 2 * 1024 * 1024 + 1))).toMatch(/2 MB/);
        expect(validateAvatarFile(file('image/png', 2 * 1024 * 1024))).toBeNull();
    });
});
