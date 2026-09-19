import { parseApiError } from './errors';
import { formatDateTime, initials, safeRedirect, statusConfirmation } from './format';
import { apiError, networkError, validationError } from '../test/helpers';

describe('parseApiError', () => {
    it('lỗi mạng (không có response)', () => {
        expect(parseApiError(networkError())).toEqual({
            status: 0,
            message: 'Cannot reach the server. Please check your connection and try again.',
            errors: {},
        });
    });

    it('422 lấy thông báo đầu tiên của mỗi trường', () => {
        const parsed = parseApiError(validationError({ email: ['The email has already been taken.', 'khác'], name: ['Required'] }));

        expect(parsed.status).toBe(422);
        expect(parsed.errors).toEqual({ email: 'The email has already been taken.', name: 'Required' });
    });

    it('giữ nguyên message của backend cho 401/403/404', () => {
        expect(parseApiError(apiError(403, { message: 'Your account has been blocked.' })).message).toBe('Your account has been blocked.');
        expect(parseApiError(apiError(404, { message: 'User not found.' })).message).toBe('User not found.');
    });

    it('429 dùng câu dễ hiểu', () => {
        expect(parseApiError(apiError(429, { message: 'Too Many Attempts.' })).message).toMatch(/Too many attempts/);
    });

    it('5xx không hiển thị nội dung máy chủ', () => {
        expect(parseApiError(apiError(500, { message: 'SQLSTATE[HY000] chi tiet noi bo' })).message).toBe('Server error. Please try again later.');
    });

    it('không có message thì dùng thông báo mặc định', () => {
        expect(parseApiError(apiError(400, {})).message).toBe('Something went wrong. Please try again.');
    });
});

describe('safeRedirect (chống open redirect)', () => {
    it('nhận đường dẫn nội bộ', () => {
        expect(safeRedirect('/users?page=2')).toBe('/users?page=2');
        expect(safeRedirect('/users/10')).toBe('/users/10');
    });

    it.each(['//evil.com', 'https://evil.com', 'http://evil.com/x', '/\\evil.com', 'javascript:alert(1)', 'evil.com', '', undefined, null, ['/a'], 42])(
        'từ chối %j và dùng đường dẫn mặc định',
        (value) => {
            expect(safeRedirect(value)).toBe('/dashboard');
        },
    );

    it('cho phép đổi đường dẫn mặc định', () => {
        expect(safeRedirect('//evil.com', '/profile')).toBe('/profile');
    });
});

describe('format', () => {
    it('formatDateTime: rỗng hoặc sai thì hiện dấu gạch', () => {
        expect(formatDateTime(null)).toBe('—');
        expect(formatDateTime('khong-phai-ngay')).toBe('—');
        expect(formatDateTime('2026-09-10T10:00:00+00:00')).toMatch(/2026/);
    });

    it('initials lấy tối đa hai chữ cái đầu', () => {
        expect(initials('Nguyen Van A')).toBe('NV');
        expect(initials('  long  ')).toBe('L');
        expect(initials('')).toBe('?');
        expect(initials(null)).toBe('?');
    });

    it('statusConfirmation tạo câu xác nhận theo trạng thái', () => {
        expect(statusConfirmation('blocked')).toBe('Are you sure you want to block this user?');
        expect(statusConfirmation('inactive')).toBe('Are you sure you want to deactivate this user?');
        expect(statusConfirmation('active')).toBe('Are you sure you want to activate this user?');
    });
});
