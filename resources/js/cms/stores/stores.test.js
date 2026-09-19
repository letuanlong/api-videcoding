import { createPinia, setActivePinia } from 'pinia';
import * as api from '../api';
import { useAuthStore } from './auth';
import { useToastStore } from './toast';
import { apiError, networkError } from '../test/helpers';

vi.mock('../api');

const USER = { id: 1, name: 'Long', email: 'long@example.com', role: 'admin' };

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
});

describe('auth store', () => {
    it('đăng nhập thành công lưu token và user, và ghi token vào sessionStorage', async () => {
        api.login.mockResolvedValue({ data: { token: 'tok-1', user: USER } });
        const auth = useAuthStore();

        const user = await auth.login({ email: 'long@example.com', password: 'x' });

        expect(api.login).toHaveBeenCalledWith({ email: 'long@example.com', password: 'x' });
        expect(user).toEqual(USER);
        expect(auth.isAuthenticated).toBe(true);
        expect(auth.token).toBe('tok-1');
        expect(auth.role).toBe('admin');
        expect(sessionStorage.getItem('cms.token')).toBe('tok-1');
    });

    it('token không bao giờ được ghi vào localStorage (chỉ sessionStorage)', async () => {
        api.login.mockResolvedValue({ data: { token: 'tok-1', user: USER } });

        await useAuthStore().login({});

        expect(localStorage.length).toBe(0);
    });

    it('đăng nhập thất bại không để lại trạng thái đăng nhập và ném lỗi cho form xử lý', async () => {
        api.login.mockRejectedValue(apiError(401, { message: 'Invalid credentials.' }));
        const auth = useAuthStore();

        await expect(auth.login({})).rejects.toBeTruthy();

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.user).toBeNull();
        expect(sessionStorage.getItem('cms.token')).toBeNull();
    });

    it('khôi phục token từ sessionStorage khi tạo store mới (F5)', () => {
        sessionStorage.setItem('cms.token', 'saved-token');
        setActivePinia(createPinia());

        const auth = useAuthStore();

        expect(auth.token).toBe('saved-token');
        expect(auth.isAuthenticated).toBe(true);
        expect(auth.user).toBeNull(); // user phải tải lại từ API
    });

    it('fetchProfile gộp thông tin vào user hiện có', async () => {
        api.getProfile.mockResolvedValue({ id: 1, name: 'Long', phone: '0900', avatar: 'a.png', role: 'admin', email: 'long@example.com' });
        const auth = useAuthStore();

        auth.setSession('t', USER);
        await auth.fetchProfile();

        expect(auth.user).toMatchObject({ id: 1, phone: '0900', avatar: 'a.png', role: 'admin' });
    });

    it('logout gọi API rồi xóa phiên', async () => {
        api.logout.mockResolvedValue({});
        const auth = useAuthStore();

        auth.setSession('tok', USER);
        await auth.logout();

        expect(api.logout).toHaveBeenCalledTimes(1);
        expect(auth.isAuthenticated).toBe(false);
        expect(auth.user).toBeNull();
        expect(sessionStorage.getItem('cms.token')).toBeNull();
    });

    it.each([
        ['token đã bị thu hồi (401)', apiError(401, { message: 'Unauthenticated.' })],
        ['mất mạng', networkError()],
        ['lỗi server', apiError(500)],
    ])('logout vẫn xóa phiên phía client khi API thất bại: %s', async (_name, error) => {
        api.logout.mockRejectedValue(error);
        const auth = useAuthStore();

        auth.setSession('tok', USER);
        await expect(auth.logout()).resolves.toBeUndefined();

        expect(auth.isAuthenticated).toBe(false);
        expect(sessionStorage.getItem('cms.token')).toBeNull();
    });

    it('vẫn hoạt động (chỉ trong bộ nhớ) khi sessionStorage bị chặn', async () => {
        const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('QuotaExceededError');
        });

        api.login.mockResolvedValue({ data: { token: 'tok', user: USER } });
        const auth = useAuthStore();

        await expect(auth.login({})).resolves.toBeTruthy();
        expect(auth.isAuthenticated).toBe(true);

        spy.mockRestore();
    });

    it('không crash khi đọc sessionStorage bị chặn', () => {
        const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });

        setActivePinia(createPinia());

        expect(useAuthStore().isAuthenticated).toBe(false);

        spy.mockRestore();
    });

    it('clear xóa cả token lẫn user', () => {
        const auth = useAuthStore();

        auth.setSession('tok', USER);
        auth.clear();

        expect(auth.token).toBeNull();
        expect(auth.user).toBeNull();
        expect(auth.role).toBeNull();
    });
});

describe('toast store', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('thêm toast thành công và lỗi', () => {
        const toast = useToastStore();

        toast.success('Đã lưu');
        toast.error('Có lỗi');

        expect(toast.toasts.map((t) => [t.type, t.message])).toEqual([['success', 'Đã lưu'], ['error', 'Có lỗi']]);
    });

    it('tự biến mất sau 5 giây', () => {
        const toast = useToastStore();

        toast.success('x');
        vi.advanceTimersByTime(4999);
        expect(toast.toasts).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(toast.toasts).toHaveLength(0);
    });

    it('đóng thủ công chỉ xóa đúng toast đó', () => {
        const toast = useToastStore();
        const first = toast.success('a');

        toast.success('b');
        toast.dismiss(first);

        expect(toast.toasts.map((t) => t.message)).toEqual(['b']);
    });

    it('duration = 0 nghĩa là không tự đóng', () => {
        const toast = useToastStore();

        toast.error('giữ lại', 0);
        vi.advanceTimersByTime(60000);

        expect(toast.toasts).toHaveLength(1);
    });
});
