import { assignableRoles, canAccessRoute, canManageUsers, canViewAuditLogs, filterableRoles, menuFor } from './permissions';

const labels = (role) => menuFor(role).map((item) => item.label);

describe('menuFor', () => {
    it('SUPERADMIN thấy Dashboard, User Management, Audit Logs, Profile', () => {
        expect(labels('superadmin')).toEqual(['Dashboard', 'User Management', 'Audit Logs', 'Profile']);
    });

    it('ADMIN thấy Dashboard, User Management, Profile (không có Audit Logs)', () => {
        expect(labels('admin')).toEqual(['Dashboard', 'User Management', 'Profile']);
    });

    it('USER chỉ thấy Dashboard và Profile', () => {
        expect(labels('user')).toEqual(['Dashboard', 'Profile']);
    });

    it('role lạ hoặc chưa đăng nhập không có mục menu nào', () => {
        expect(menuFor('hacker')).toEqual([]);
        expect(menuFor(null)).toEqual([]);
    });
});

describe('quyền theo role', () => {
    it('chỉ SUPERADMIN và ADMIN quản lý user', () => {
        expect(canManageUsers('superadmin')).toBe(true);
        expect(canManageUsers('admin')).toBe(true);
        expect(canManageUsers('user')).toBe(false);
        expect(canManageUsers(null)).toBe(false);
    });

    it('chỉ SUPERADMIN xem audit log', () => {
        expect(canViewAuditLogs('superadmin')).toBe(true);
        expect(canViewAuditLogs('admin')).toBe(false);
        expect(canViewAuditLogs('user')).toBe(false);
    });

    it('role được gán: ADMIN chỉ USER, SUPERADMIN là ADMIN và USER, không ai gán được superadmin', () => {
        expect(assignableRoles('admin')).toEqual(['user']);
        expect(assignableRoles('superadmin')).toEqual(['admin', 'user']);
        expect(assignableRoles('user')).toEqual([]);
        expect(assignableRoles('superadmin')).not.toContain('superadmin');
    });

    it('bộ lọc role: ADMIN chỉ USER, SUPERADMIN thêm superadmin', () => {
        expect(filterableRoles('admin')).toEqual(['user']);
        expect(filterableRoles('superadmin')).toEqual(['admin', 'user', 'superadmin']);
        expect(filterableRoles('user')).toEqual([]);
    });
});

describe('canAccessRoute', () => {
    it('route không khai báo roles thì ai đã đăng nhập cũng vào được', () => {
        expect(canAccessRoute('user', undefined)).toBe(true);
        expect(canAccessRoute('user', [])).toBe(true);
    });

    it('route có roles thì chỉ role trong danh sách được vào', () => {
        expect(canAccessRoute('admin', ['superadmin', 'admin'])).toBe(true);
        expect(canAccessRoute('user', ['superadmin', 'admin'])).toBe(false);
        expect(canAccessRoute(null, ['superadmin'])).toBe(false);
    });
});
