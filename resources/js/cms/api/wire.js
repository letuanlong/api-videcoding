import { http, installInterceptors } from './http';

/**
 * Nối axios với store và router: gắn token vào request, và khi API trả 401 (token hết hạn/bị thu hồi)
 * thì xóa phiên rồi chuyển về trang login, nhớ trang đang xem để quay lại sau khi đăng nhập.
 */
export function wireHttp(router, auth) {
    return installInterceptors(http, {
        getToken: () => auth.token,
        onUnauthenticated: () => {
            // Đã ở trang login (hoặc phiên đã được xóa trước đó) thì không làm gì thêm
            if (!auth.isAuthenticated) {
                return;
            }

            const current = router.currentRoute.value;

            auth.clear();

            router.replace({ name: 'login', query: { redirect: current.fullPath, notice: 'session_expired' } });
        },
    });
}
