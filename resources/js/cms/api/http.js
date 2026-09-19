import axios from 'axios';

/**
 * Axios dùng chung cho CMS. Cùng origin với Laravel nên không cần CORS.
 * Xác thực bằng Bearer token (không dùng cookie) nên không cần CSRF.
 */
export const http = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/json' },
    timeout: 20000,
});

/**
 * Gắn token vào mọi request và xử lý 401 tập trung.
 * Tách khỏi store/router để dễ test: mọi thứ phụ thuộc đều truyền vào qua tham số.
 *
 * @param {import('axios').AxiosInstance} instance
 * @param {{ getToken: () => (string|null), onUnauthenticated: () => void }} hooks
 * @returns {() => void} hàm gỡ interceptor
 */
export function installInterceptors(instance, { getToken, onUnauthenticated }) {
    const requestId = instance.interceptors.request.use((config) => {
        const token = getToken();

        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }

        return config;
    });

    const responseId = instance.interceptors.response.use(
        (response) => response,
        (error) => {
            // 401 ở /login nghĩa là sai thông tin đăng nhập (form tự hiển thị), không phải hết phiên
            const isLogin = error.config?.url === '/login';

            if (error.response?.status === 401 && !isLogin) {
                onUnauthenticated();
            }

            return Promise.reject(error);
        },
    );

    return () => {
        instance.interceptors.request.eject(requestId);
        instance.interceptors.response.eject(responseId);
    };
}
