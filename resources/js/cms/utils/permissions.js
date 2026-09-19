// Chỉ phục vụ UX (ẩn/hiện menu, giới hạn lựa chọn). Backend luôn kiểm tra lại quyền ở mọi endpoint.

export const ROLES = Object.freeze({
    SUPERADMIN: 'superadmin',
    ADMIN: 'admin',
    USER: 'user',
});

export const STATUSES = ['active', 'inactive', 'blocked'];

export const ROLE_LABELS = { superadmin: 'Super Admin', admin: 'Admin', user: 'User' };
export const STATUS_LABELS = { active: 'Active', inactive: 'Inactive', blocked: 'Blocked' };

const MENU = [
    { key: 'dashboard', label: 'Dashboard', to: { name: 'dashboard' }, roles: ['superadmin', 'admin', 'user'] },
    { key: 'users', label: 'User Management', to: { name: 'users' }, roles: ['superadmin', 'admin'] },
    { key: 'audit-logs', label: 'Audit Logs', to: { name: 'audit-logs' }, roles: ['superadmin'] },
    { key: 'profile', label: 'Profile', to: { name: 'profile' }, roles: ['superadmin', 'admin', 'user'] },
];

/** Các mục menu (không gồm Logout, vì Logout là hành động chứ không phải trang). */
export function menuFor(role) {
    return MENU.filter((item) => item.roles.includes(role));
}

export function canManageUsers(role) {
    return [ROLES.SUPERADMIN, ROLES.ADMIN].includes(role);
}

export function canViewAuditLogs(role) {
    return role === ROLES.SUPERADMIN;
}

/** Role mà người dùng được gán khi tạo/sửa (không ai gán được superadmin). */
export function assignableRoles(role) {
    if (role === ROLES.SUPERADMIN) return [ROLES.ADMIN, ROLES.USER];
    if (role === ROLES.ADMIN) return [ROLES.USER];

    return [];
}

/** Role hiển thị trong bộ lọc danh sách. SUPERADMIN được lọc thêm superadmin (chỉ xem). */
export function filterableRoles(role) {
    if (role === ROLES.SUPERADMIN) return [ROLES.ADMIN, ROLES.USER, ROLES.SUPERADMIN];
    if (role === ROLES.ADMIN) return [ROLES.USER];

    return [];
}

/** Cho phép vào route có meta.roles hay không; route không khai báo roles thì mọi người dùng đã đăng nhập đều vào được. */
export function canAccessRoute(role, routeRoles) {
    return !routeRoles || routeRoles.length === 0 || routeRoles.includes(role);
}
