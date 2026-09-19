import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import * as api from '../api';

const TOKEN_KEY = 'cms.token';

// Token lưu trong sessionStorage: sống qua F5 nhưng mất khi đóng tab, không bị chia sẻ giữa các tab.
// Mọi thao tác storage đều bọc try/catch vì có thể bị chặn (chế độ riêng tư, cấu hình trình duyệt).
function readToken() {
    try {
        return sessionStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

function writeToken(token) {
    try {
        if (token) {
            sessionStorage.setItem(TOKEN_KEY, token);
        } else {
            sessionStorage.removeItem(TOKEN_KEY);
        }
    } catch {
        // Không lưu được thì phiên chỉ sống trong bộ nhớ
    }
}

export const useAuthStore = defineStore('auth', () => {
    const token = ref(readToken());
    const user = ref(null);

    const isAuthenticated = computed(() => Boolean(token.value));
    const role = computed(() => user.value?.role ?? null);

    function setSession(newToken, newUser) {
        token.value = newToken;
        user.value = newUser;
        writeToken(newToken);
    }

    /** Cập nhật thông tin user, giữ lại các trường đã có (đăng nhập chỉ trả về id/name/email/role). */
    function setUser(partial) {
        user.value = { ...(user.value ?? {}), ...partial };
    }

    function clear() {
        token.value = null;
        user.value = null;
        writeToken(null);
    }

    async function login(credentials) {
        const response = await api.login(credentials);

        setSession(response.data.token, response.data.user);

        return response.data.user;
    }

    /** Tải lại hồ sơ, dùng khi F5 (còn token nhưng mất thông tin user). */
    async function fetchProfile() {
        setUser(await api.getProfile());

        return user.value;
    }

    /** Đăng xuất luôn xóa phiên phía client, kể cả khi gọi API thất bại (mạng lỗi, token đã hết hạn). */
    async function logout() {
        try {
            await api.logout();
        } catch {
            // Bỏ qua: token có thể đã bị thu hồi
        } finally {
            clear();
        }
    }

    return { token, user, isAuthenticated, role, login, logout, fetchProfile, setUser, setSession, clear };
});
