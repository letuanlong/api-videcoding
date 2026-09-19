/** Hiển thị thời gian theo múi giờ của trình duyệt; giá trị rỗng hiển thị dấu gạch ngang. */
export function formatDateTime(iso) {
    if (!iso) return '—';

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) return '—';

    return date.toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

export function initials(name) {
    return (name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('') || '?';
}

/**
 * Chỉ chấp nhận đường dẫn nội bộ của router để chống open redirect sau khi đăng nhập.
 */
export function safeRedirect(value, fallback = '/dashboard') {
    if (typeof value !== 'string') return fallback;
    if (!value.startsWith('/') || value.startsWith('//') || value.includes('://') || value.includes('\\')) return fallback;

    return value;
}

const STATUS_VERBS = { active: 'activate', inactive: 'deactivate', blocked: 'block' };

/** Câu xác nhận khi đổi trạng thái, ví dụ "Are you sure you want to block this user?". */
export function statusConfirmation(status) {
    return `Are you sure you want to ${STATUS_VERBS[status] ?? 'change the status of'} this user?`;
}
