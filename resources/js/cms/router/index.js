import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { canAccessRoute } from '../utils/permissions';

export const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/LoginPage.vue'),
        meta: { guest: true, title: 'Login' },
    },
    {
        path: '/',
        component: () => import('../layouts/CmsLayout.vue'),
        meta: { requiresAuth: true },
        children: [
            { path: '', redirect: { name: 'dashboard' } },
            { path: 'dashboard', name: 'dashboard', component: () => import('../pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
            { path: 'profile', name: 'profile', component: () => import('../pages/ProfilePage.vue'), meta: { title: 'Profile' } },
            {
                path: 'users',
                name: 'users',
                component: () => import('../pages/users/UserListPage.vue'),
                meta: { roles: ['superadmin', 'admin'], title: 'User Management' },
            },
            {
                path: 'users/create',
                name: 'user-create',
                component: () => import('../pages/users/UserCreatePage.vue'),
                meta: { roles: ['superadmin', 'admin'], title: 'Create User' },
            },
            {
                path: 'users/:id(\\d+)',
                name: 'user-detail',
                component: () => import('../pages/users/UserDetailPage.vue'),
                meta: { roles: ['superadmin', 'admin'], title: 'User Detail' },
            },
            {
                path: 'users/:id(\\d+)/edit',
                name: 'user-edit',
                component: () => import('../pages/users/UserEditPage.vue'),
                meta: { roles: ['superadmin', 'admin'], title: 'Edit User' },
            },
            {
                path: 'audit-logs',
                name: 'audit-logs',
                component: () => import('../pages/AuditLogsPage.vue'),
                meta: { roles: ['superadmin'], title: 'Audit Logs' },
            },
            { path: 'forbidden', name: 'forbidden', component: () => import('../pages/ForbiddenPage.vue'), meta: { title: 'Forbidden' } },
        ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../pages/NotFoundPage.vue'), meta: { title: 'Not found' } },
];

/**
 * Guard điều hướng. Chỉ là lớp UX: chặn sớm để người dùng không thấy trang trắng/lỗi,
 * còn bảo mật thật nằm ở backend (mọi API đều tự kiểm tra token và role).
 */
export async function authGuard(to) {
    const auth = useAuthStore();

    if (to.meta.guest) {
        return auth.isAuthenticated ? { name: 'dashboard' } : true;
    }

    // Trang not-found dùng chung cho cả người chưa đăng nhập
    if (!to.matched.some((record) => record.meta.requiresAuth)) {
        return true;
    }

    if (!auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    // F5: còn token nhưng chưa có thông tin user thì tải lại hồ sơ
    if (!auth.user) {
        try {
            await auth.fetchProfile();
        } catch {
            auth.clear();

            return { name: 'login', query: { redirect: to.fullPath, notice: 'session_expired' } };
        }
    }

    const allowed = to.matched.every((record) => canAccessRoute(auth.role, record.meta.roles));

    return allowed ? true : { name: 'forbidden' };
}

export function createCmsRouter(history = createWebHistory('/cms/')) {
    const router = createRouter({ history, routes });

    router.beforeEach(authGuard);

    router.afterEach((to) => {
        document.title = to.meta.title ? `${to.meta.title} · CMS` : 'CMS';
    });

    return router;
}
