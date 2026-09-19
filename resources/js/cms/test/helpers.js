import { afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import App from '../App.vue';
import { createCmsRouter, routes } from '../router';
import { useAuthStore } from '../stores/auth';

export const flush = flushPromises;

// Gỡ mọi component đã mount sau mỗi test để request/timer bất đồng bộ của test trước không rò sang test sau
const mounted = [];

afterEach(() => {
    while (mounted.length) {
        mounted.pop().unmount();
    }
});

/** Promise có thể tự resolve/reject sau, để kiểm tra trạng thái đang tải. */
export function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

const ALL_ABILITIES = { update: true, delete: true, change_status: true, reset_password: true };
export const NO_ABILITIES = { update: false, delete: false, change_status: false, reset_password: false };

/** User đúng định dạng UserResource của backend. */
export function makeUser(overrides = {}) {
    return {
        id: 10,
        name: 'Nguyen Van A',
        email: 'a@example.com',
        phone: '0900000000',
        avatar: null,
        role: 'user',
        status: 'active',
        last_login_at: '2026-09-10T10:00:00+00:00',
        created_at: '2026-09-01T08:00:00+00:00',
        updated_at: '2026-09-01T08:00:00+00:00',
        abilities: { ...ALL_ABILITIES },
        ...overrides,
    };
}

export function makeMeta(overrides = {}) {
    return { current_page: 1, per_page: 20, total: 1, last_page: 1, ...overrides };
}

/** Response phân trang đúng định dạng { success, data, meta }. */
export function listResponse(users, metaOverrides = {}) {
    return { success: true, data: users, meta: makeMeta({ total: users.length, ...metaOverrides }) };
}

/** Lỗi giống axios: có error.response.status/data. */
export function apiError(status, data = {}) {
    const error = new Error(`Request failed with status code ${status}`);
    error.response = { status, data: { success: false, ...data } };

    return error;
}

export function validationError(errors, message = 'Validation failed.') {
    return apiError(422, { message, errors });
}

export function networkError() {
    return new Error('Network Error');
}

function signIn(auth, role, me) {
    auth.setSession('test-token', { id: 1, name: 'Me', email: 'me@example.com', role, ...me });
}

/**
 * Mount một trang/component trong môi trường có Pinia + router (memory). Chưa gắn guard đăng nhập
 * để test tập trung vào chính trang đó.
 */
export async function mountAt(component, { path = '/', role = 'superadmin', authenticated = true, props = {}, me = {}, attachTo = document.body } = {}) {
    const pinia = createPinia();

    setActivePinia(pinia);

    const auth = useAuthStore();

    if (authenticated) {
        signIn(auth, role, me);
    }

    const router = createRouter({ history: createMemoryHistory(), routes });

    await router.push(path);
    await router.isReady();

    const wrapper = mount(component, { props, attachTo, global: { plugins: [pinia, router] } });

    mounted.push(wrapper);

    await flushPromises();

    return { wrapper, router, auth, pinia };
}

/** Mount toàn bộ ứng dụng (App + router có guard thật), dùng cho kịch bản QA nhiều bước. */
export async function mountApp({ path = '/', role = null, me = {} } = {}) {
    const pinia = createPinia();

    setActivePinia(pinia);

    const auth = useAuthStore();

    if (role) {
        signIn(auth, role, me);
    }

    const router = createCmsRouter(createMemoryHistory());

    await router.push(path);
    await router.isReady();

    const wrapper = mount(App, { attachTo: document.body, global: { plugins: [pinia, router] } });

    mounted.push(wrapper);

    await flushPromises();

    return { wrapper, router, auth, pinia };
}

export const byTestId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);
export const allByTestId = (wrapper, id) => wrapper.findAll(`[data-testid="${id}"]`);
