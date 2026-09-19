import { http } from './http';

/** Bỏ các tham số rỗng và giá trị 'all' để URL gọn và backend không nhận filter thừa. */
export function cleanParams(params = {}) {
    return Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined && value !== 'all'),
    );
}

// ---- Auth ----
export const login = (credentials) => http.post('/login', credentials).then((r) => r.data);
export const logout = () => http.post('/logout').then((r) => r.data);

// ---- Dashboard ----
export const getDashboard = () => http.get('/dashboard').then((r) => r.data.data);

// ---- Profile ----
export const getProfile = () => http.get('/profile').then((r) => r.data.data);

/**
 * Laravel không đọc được multipart với PUT nên khi có file phải gửi POST kèm _method=PUT.
 * Không có file thì gửi JSON; removeAvatar gửi avatar: null để xóa ảnh.
 */
export function updateProfile({ name, phone }, { avatarFile = null, removeAvatar = false } = {}) {
    if (avatarFile) {
        const form = new FormData();
        form.append('_method', 'PUT');
        form.append('name', name);
        form.append('phone', phone ?? '');
        form.append('avatar', avatarFile);

        return http.post('/profile', form).then((r) => r.data);
    }

    const body = { name, phone: phone ?? '' };

    if (removeAvatar) {
        body.avatar = null;
    }

    return http.put('/profile', body).then((r) => r.data);
}

export const changePassword = (payload) => http.post('/profile/change-password', payload).then((r) => r.data);

// ---- Users ----
export const listUsers = (params) => http.get('/admin/users', { params: cleanParams(params) }).then((r) => r.data);
export const getUser = (id) => http.get(`/admin/users/${id}`).then((r) => r.data.data);
export const createUser = (payload) => http.post('/admin/users', payload).then((r) => r.data);
export const updateUser = (id, payload) => http.put(`/admin/users/${id}`, payload).then((r) => r.data);
export const changeUserStatus = (id, status) => http.patch(`/admin/users/${id}/status`, { status }).then((r) => r.data);
export const deleteUser = (id) => http.delete(`/admin/users/${id}`).then((r) => r.data);
export const resetUserPassword = (id, payload) => http.post(`/admin/users/${id}/reset-password`, payload).then((r) => r.data);

// ---- Audit logs ----
export const listAuditLogs = (params) => http.get('/admin/audit-logs', { params: cleanParams(params) }).then((r) => r.data);
