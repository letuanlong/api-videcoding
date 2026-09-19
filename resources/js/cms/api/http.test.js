import axios from 'axios';
import { installInterceptors } from './http';
import { cleanParams } from './index';

/** Axios giả: adapter trả về (hoặc ném) theo kịch bản, đồng thời ghi lại request cuối. */
function makeClient(respond) {
    const seen = [];
    const client = axios.create({
        baseURL: '/api',
        adapter: async (config) => {
            seen.push(config);

            const { status, data } = respond(config);
            const response = { data, status, statusText: '', headers: {}, config };

            if (status >= 400) {
                const error = new Error(`status ${status}`);
                error.config = config;
                error.response = response;
                throw error;
            }

            return response;
        },
    });

    return { client, seen };
}

describe('installInterceptors', () => {
    it('gắn Bearer token vào request khi có token', async () => {
        const { client, seen } = makeClient(() => ({ status: 200, data: {} }));

        installInterceptors(client, { getToken: () => 'abc123', onUnauthenticated: vi.fn() });
        await client.get('/profile');

        expect(seen[0].headers.Authorization).toBe('Bearer abc123');
    });

    it('không gắn Authorization khi chưa có token', async () => {
        const { client, seen } = makeClient(() => ({ status: 200, data: {} }));

        installInterceptors(client, { getToken: () => null, onUnauthenticated: vi.fn() });
        await client.get('/x');

        expect(seen[0].headers.Authorization).toBeUndefined();
    });

    it('lấy token mới nhất ở mỗi request (không cache)', async () => {
        const { client, seen } = makeClient(() => ({ status: 200, data: {} }));
        let token = 'first';

        installInterceptors(client, { getToken: () => token, onUnauthenticated: vi.fn() });

        await client.get('/a');
        token = 'second';
        await client.get('/b');

        expect(seen.map((c) => c.headers.Authorization)).toEqual(['Bearer first', 'Bearer second']);
    });

    it('401 ở API thường: gọi onUnauthenticated và vẫn ném lỗi cho nơi gọi', async () => {
        const { client } = makeClient(() => ({ status: 401, data: { message: 'Unauthenticated.' } }));
        const onUnauthenticated = vi.fn();

        installInterceptors(client, { getToken: () => 'abc', onUnauthenticated });

        await expect(client.get('/profile')).rejects.toMatchObject({ response: { status: 401 } });
        expect(onUnauthenticated).toHaveBeenCalledTimes(1);
    });

    it('401 ở /login là sai thông tin đăng nhập, không phải hết phiên', async () => {
        const { client } = makeClient(() => ({ status: 401, data: { message: 'Invalid credentials.' } }));
        const onUnauthenticated = vi.fn();

        installInterceptors(client, { getToken: () => null, onUnauthenticated });

        await expect(client.post('/login', {})).rejects.toBeTruthy();
        expect(onUnauthenticated).not.toHaveBeenCalled();
    });

    it.each([403, 404, 422, 429, 500])('%s không kích hoạt xử lý hết phiên', async (status) => {
        const { client } = makeClient(() => ({ status, data: {} }));
        const onUnauthenticated = vi.fn();

        installInterceptors(client, { getToken: () => 'abc', onUnauthenticated });

        await expect(client.get('/x')).rejects.toBeTruthy();
        expect(onUnauthenticated).not.toHaveBeenCalled();
    });

    it('hàm gỡ interceptor trả về hoạt động đúng', async () => {
        const { client, seen } = makeClient(() => ({ status: 200, data: {} }));
        const eject = installInterceptors(client, { getToken: () => 'abc', onUnauthenticated: vi.fn() });

        eject();
        await client.get('/x');

        expect(seen[0].headers.Authorization).toBeUndefined();
    });
});

describe('cleanParams', () => {
    it('bỏ rỗng, null, undefined và giá trị all', () => {
        expect(cleanParams({ search: '', role: 'all', status: 'active', page: 2, x: null, y: undefined })).toEqual({ status: 'active', page: 2 });
    });

    it('giữ số 0 và false', () => {
        expect(cleanParams({ a: 0, b: false })).toEqual({ a: 0, b: false });
    });
});
