import { afterEach, beforeEach } from 'vitest';

// Mỗi test bắt đầu với storage sạch để trạng thái đăng nhập không rò rỉ giữa các test
beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
});

afterEach(() => {
    document.body.innerHTML = '';
});
