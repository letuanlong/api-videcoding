// Validate phía frontend chỉ để phản hồi nhanh cho người dùng; backend vẫn là nơi quyết định.

export const PASSWORD_MIN = 8;
export const PASSWORD_MAX = 72;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function validateEmail(value) {
    const email = (value ?? '').trim();

    if (!email) return 'Email is required.';
    if (email.length > 255) return 'Email must not be longer than 255 characters.';
    if (!EMAIL_PATTERN.test(email)) return 'Please enter a valid email address.';

    return null;
}

/**
 * Chính sách mật khẩu khớp backend (Password::min(8)->max(72)->mixedCase()->numbers()->symbols()).
 */
export function validatePassword(value) {
    const password = value ?? '';

    if (!password) return 'Password is required.';
    if (password.length < PASSWORD_MIN) return `Password must be at least ${PASSWORD_MIN} characters.`;
    if (password.length > PASSWORD_MAX) return `Password must not be longer than ${PASSWORD_MAX} characters.`;
    if (!/\p{Ll}/u.test(password) || !/\p{Lu}/u.test(password)) return 'Password must contain both uppercase and lowercase letters.';
    if (!/\p{N}/u.test(password)) return 'Password must contain at least one number.';
    if (!/[\p{Z}\p{S}\p{P}]/u.test(password)) return 'Password must contain at least one symbol.';

    return null;
}

export function validatePasswordConfirmation(password, confirmation) {
    if (!confirmation) return 'Please confirm the password.';
    if (password !== confirmation) return 'Password confirmation does not match.';

    return null;
}

export function validateName(value) {
    const name = (value ?? '').trim();

    if (!name) return 'Name is required.';
    if (name.length > 255) return 'Name must not be longer than 255 characters.';

    return null;
}

export function validatePhone(value) {
    const phone = (value ?? '').trim();

    if (phone.length > 20) return 'Phone must not be longer than 20 characters.';

    return null;
}

/** Gom các lỗi khác null thành một object { field: message }. */
function collect(entries) {
    return Object.fromEntries(entries.filter(([, message]) => message));
}

export function validateLogin({ email, password }) {
    return collect([
        ['email', validateEmail(email)],
        ['password', password ? null : 'Password is required.'],
    ]);
}

/**
 * @param {'create'|'edit'} mode create yêu cầu mật khẩu, edit thì không có trường mật khẩu
 */
export function validateUserForm(values, mode) {
    const entries = [
        ['name', validateName(values.name)],
        ['email', validateEmail(values.email)],
        ['phone', validatePhone(values.phone)],
        ['role', values.role ? null : 'Role is required.'],
        ['status', values.status ? null : 'Status is required.'],
    ];

    if (mode === 'create') {
        entries.push(
            ['password', validatePassword(values.password)],
            ['password_confirmation', validatePasswordConfirmation(values.password, values.password_confirmation)],
        );
    }

    return collect(entries);
}

export function validatePasswordPair({ password, password_confirmation }) {
    return collect([
        ['password', validatePassword(password)],
        ['password_confirmation', validatePasswordConfirmation(password, password_confirmation)],
    ]);
}

export function validateProfile({ name, phone }) {
    return collect([
        ['name', validateName(name)],
        ['phone', validatePhone(phone)],
    ]);
}

export const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
export const AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export function validateAvatarFile(file) {
    if (!file) return null;
    if (!AVATAR_TYPES.includes(file.type)) return 'Avatar must be a JPG, PNG or WebP image.';
    if (file.size > AVATAR_MAX_BYTES) return 'Avatar must not be larger than 2 MB.';

    return null;
}
