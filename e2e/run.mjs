// E2E thật: Laravel (PHP built-in server) + bản build của CMS + trình duyệt Chrome/Edge thật.
// Chạy trên một DB SQLite tạm nên không đụng dữ liệu dev. Cách chạy: `npm run test:e2e`.
// Yêu cầu: PHP trong PATH, đã cài Chrome hoặc Edge. Biến môi trường tùy chọn: E2E_PORT, E2E_HEADED=1, E2E_KEEP=1.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { chromium } from 'playwright-core';

const ROOT = path.resolve(import.meta.dirname, '..');
const PORT = Number(process.env.E2E_PORT ?? 8765);
const BASE = `http://127.0.0.1:${PORT}`;
const ARTIFACTS = path.join(ROOT, 'e2e', 'artifacts');
const DB_FILE = path.join(os.tmpdir(), `cms-e2e-${Date.now()}.sqlite`);
const AVATAR_DIR = path.join(ROOT, 'storage', 'app', 'public', 'avatars');

const PASSWORD = 'Password@123'; // mật khẩu của 3 tài khoản mẫu (UserSeeder)

// Mọi cấu hình dưới đây ghi đè .env, để E2E chỉ chạy trên DB tạm
const ENV = {
    ...process.env,
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: DB_FILE,
    DB_URL: '',
    CACHE_STORE: 'array', // tránh throttle đăng nhập làm E2E chập chờn
    SESSION_DRIVER: 'array',
    QUEUE_CONNECTION: 'sync',
    BCRYPT_ROUNDS: '4',
    LOG_CHANNEL: 'stderr',
};

const php = (...args) => execFileSync('php', ['artisan', ...args], { cwd: ROOT, env: ENV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

// ---------------------------------------------------------------- harness
const results = [];
let currentPage = null;
const pageProblems = [];

async function step(name, fn) {
    try {
        await fn();
        results.push({ name, ok: true });
        console.log(`  ✓ ${name}`);
    } catch (error) {
        results.push({ name, ok: false, error });
        console.log(`  ✗ ${name}\n      ${String(error.message ?? error).split('\n').slice(0, 9).join('\n      ')}`);

        if (currentPage) {
            fs.mkdirSync(ARTIFACTS, { recursive: true });
            await currentPage.screenshot({ path: path.join(ARTIFACTS, `${results.length}-fail.png`), fullPage: true }).catch(() => {});
        }
    }
}

/** Thử lại một khẳng định cho tới khi đúng hoặc hết thời gian (giao diện cập nhật bất đồng bộ). */
async function eventually(fn, timeout = 8000) {
    const start = Date.now();
    let last;

    while (Date.now() - start < timeout) {
        try {
            return await fn();
        } catch (error) {
            last = error;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
    }

    throw last;
}

const text = (page, id) => page.getByTestId(id).first().innerText();

// ---------------------------------------------------------------- hạ tầng
let server;
let serverLog = '';

async function startServer() {
    const router = path.join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');

    server = spawn('php', ['-S', `127.0.0.1:${PORT}`, router], { cwd: path.join(ROOT, 'public'), env: ENV });
    server.stderr.on('data', (chunk) => { serverLog += chunk; });
    server.stdout.on('data', (chunk) => { serverLog += chunk; });

    for (let i = 0; i < 100; i++) {
        try {
            const response = await fetch(`${BASE}/up`);

            if (response.ok) return;
        } catch {
            // chưa sẵn sàng
        }

        await new Promise((resolve) => setTimeout(resolve, 100));
    }

    throw new Error('Máy chủ PHP không khởi động được');
}

function findBrowser() {
    const candidates = [
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ];

    return candidates.find((candidate) => fs.existsSync(candidate));
}

const listAvatars = () => (fs.existsSync(AVATAR_DIR) ? fs.readdirSync(AVATAR_DIR) : []);

// ---------------------------------------------------------------- thao tác giao diện
async function newSession(browser) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();

    // Mọi lỗi CSP/JS trong suốt E2E đều bị ghi nhận và làm hỏng kết quả cuối
    page.on('console', (message) => {
        const line = message.text();

        if (/Content Security Policy|Refused to (load|execute|apply|connect)/i.test(line)) {
            pageProblems.push(`CSP: ${line}`);
        }
    });
    page.on('pageerror', (error) => pageProblems.push(`JS: ${error.message}`));

    currentPage = page;

    return { context, page };
}

async function login(page, email, password = PASSWORD) {
    await page.goto(`${BASE}/cms/login`);
    await page.getByTestId('login-email').fill(email);
    await page.getByTestId('login-password').fill(password);
    await page.getByTestId('login-submit').click();
}

async function loginAndLand(page, email, password = PASSWORD) {
    await login(page, email, password);
    await page.waitForURL('**/cms/dashboard');
    await page.getByTestId('sidebar').waitFor();
}

const menu = (page) => page.locator('nav[aria-label="Main"] a').allInnerTexts();

/** Gọi API bằng token của phiên hiện tại (đọc từ sessionStorage) để kiểm tra backend chặn độc lập với giao diện. */
const apiStatus = (page, url, options = {}) =>
    page.evaluate(async ({ url, options }) => {
        const token = sessionStorage.getItem('cms.token');
        const response = await fetch(url, {
            ...options,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        });

        return { status: response.status, body: await response.json().catch(() => null) };
    }, { url, options });

// ---------------------------------------------------------------- kịch bản
async function run() {
    console.log('Chuẩn bị: build frontend, tạo DB tạm, nạp dữ liệu mẫu, khởi động máy chủ…');

    execFileSync('npm', ['run', 'build'], { cwd: ROOT, stdio: 'ignore', shell: true });
    fs.writeFileSync(DB_FILE, '');
    php('migrate:fresh', '--seed', '--force');
    php('tinker', '--execute', 'App\\Models\\User::factory()->count(25)->create();');
    await startServer();

    const executable = findBrowser();

    assert.ok(executable, 'Không tìm thấy Chrome hoặc Edge trên máy');

    const browser = await chromium.launch({ executablePath: executable, headless: process.env.E2E_HEADED !== '1' });
    const avatarsBefore = new Set(listAvatars());

    try {
        // ========================================================= truy cập chưa đăng nhập
        console.log('\nTrang đăng nhập và bảo mật cơ bản');
        {
            const { context, page } = await newSession(browser);

            await step('chưa đăng nhập vào /cms/users bị chuyển về login và nhớ trang định vào', async () => {
                await page.goto(`${BASE}/cms/users`);
                await page.waitForURL('**/cms/login**');
                assert.match(page.url(), /redirect=%2Fusers|redirect=\/users/);
            });

            await step('trang CMS gửi CSP và header chống nhúng iframe', async () => {
                const response = await page.request.get(`${BASE}/cms`);
                const headers = response.headers();

                assert.match(headers['content-security-policy'], /script-src 'self'/);
                assert.equal(headers['x-frame-options'], 'DENY');
                assert.equal(headers['x-content-type-options'], 'nosniff');
            });

            await step('sai mật khẩu hiện lỗi chung, không lộ email có tồn tại hay không', async () => {
                await login(page, 'superadmin@example.com', 'sai-mat-khau');
                await eventually(async () => assert.equal(await text(page, 'login-error'), 'Invalid email or password.'));

                await login(page, 'khong-co@example.com', 'sai-mat-khau');
                await eventually(async () => assert.equal(await text(page, 'login-error'), 'Invalid email or password.'));
            });

            await step('form đăng nhập validate phía client (email sai định dạng)', async () => {
                await page.goto(`${BASE}/cms/login`);
                await page.getByTestId('login-email').fill('khong-phai-email');
                await page.getByTestId('login-password').fill('x');
                await page.getByTestId('login-submit').click();
                await eventually(async () => assert.match(await page.locator('body').innerText(), /valid email/i));
            });

            await step('mật khẩu không bị lưu vào storage của trình duyệt', async () => {
                await loginAndLand(page, 'superadmin@example.com');

                const dump = await page.evaluate(() => JSON.stringify({ ...sessionStorage }) + JSON.stringify({ ...localStorage }));

                assert.ok(!dump.includes(PASSWORD));
                assert.equal(await page.evaluate(() => localStorage.length), 0);
                assert.ok(await page.evaluate(() => sessionStorage.getItem('cms.token')));
            });

            await context.close();
        }

        // ========================================================= SUPERADMIN
        console.log('\nQA 1 - SUPERADMIN');
        const created = { email: 'e2e.created@example.com', password: 'E2E@Passw0rd1', newPassword: 'NewE2E@Pass123' };
        {
            const { context, page } = await newSession(browser);

            await step('đăng nhập vào dashboard, menu đủ 4 mục + Logout', async () => {
                await loginAndLand(page, 'superadmin@example.com');
                assert.deepEqual(await menu(page), ['Dashboard', 'User Management', 'Audit Logs', 'Profile']);
                assert.equal(await text(page, 'nav-logout'), 'Logout');
            });

            await step('dashboard hiển thị thống kê đúng (28 = 1 admin + 27 user)', async () => {
                await eventually(async () => assert.match(await text(page, 'card-total'), /28/));
                assert.match(await text(page, 'card-active'), /28/);
                assert.match(await text(page, 'card-blocked'), /0/);
            });

            await step('danh sách user phân trang: 28 bản ghi, 20/trang', async () => {
                await page.getByTestId('nav-users').click();
                await eventually(async () => assert.equal(await text(page, 'pagination-summary'), 'Showing 1–20 of 28'));
                assert.equal(await page.locator('[data-testid^="user-row-"]').count(), 20);

                await page.getByTestId('page-2').click();
                await eventually(async () => assert.equal(await text(page, 'pagination-summary'), 'Showing 21–28 of 28'));
                assert.match(page.url(), /page=2/);
            });

            await step('tìm kiếm theo email, lọc theo role và status', async () => {
                await page.goto(`${BASE}/cms/users`);
                await page.getByTestId('filter-search').fill('admin@example');
                await page.getByTestId('filter-submit').click();
                await eventually(async () => assert.equal(await page.locator('[data-testid^="user-row-"]').count(), 1));
                assert.match(await page.locator('[data-testid^="user-row-"]').first().innerText(), /admin@example\.com/);

                await page.goto(`${BASE}/cms/users`);
                await page.getByTestId('filter-role').selectOption('admin');
                await page.getByTestId('filter-submit').click();
                await eventually(async () => assert.equal(await text(page, 'pagination-summary'), 'Showing 1–1 of 1'));

                await page.goto(`${BASE}/cms/users?status=blocked`);
                await eventually(async () => assert.match(await text(page, 'state-empty'), /No users found\./));
                assert.equal(await page.getByTestId('clear-filters').count(), 1);
            });

            await step('tạo ADMIN mới: dropdown chỉ có Admin và User, validate email trùng, thành công về danh sách', async () => {
                await page.goto(`${BASE}/cms/users/create`);
                await eventually(async () => assert.deepEqual(await page.getByTestId('field-role').locator('option').allInnerTexts(), ['Admin', 'User']));

                await page.getByTestId('field-name').fill('E2E Created');
                await page.getByTestId('field-email').fill('user@example.com'); // đã tồn tại
                await page.getByTestId('field-password').fill(created.password);
                await page.getByTestId('field-password-confirmation').fill(created.password);
                await page.getByTestId('field-role').selectOption('admin');
                await page.getByTestId('form-submit').click();
                await eventually(async () => assert.match(await page.locator('#user-email-error').innerText(), /already been taken/i));
                assert.match(page.url(), /users\/create/);

                await page.getByTestId('field-email').fill(created.email);
                await page.getByTestId('form-submit').click();
                await page.waitForURL(/\/cms\/users$/);
                await eventually(async () => assert.match(await page.getByTestId('toast-success').first().innerText(), /created successfully/i));
            });

            await step('xem chi tiết rồi sửa tên user vừa tạo', async () => {
                await page.getByTestId('filter-search').fill('e2e.created');
                await page.getByTestId('filter-submit').click();
                await eventually(async () => assert.equal(await page.locator('[data-testid^="user-row-"]').count(), 1));

                await page.locator('[data-testid="action-view"]').first().click();
                await eventually(async () => assert.equal(await text(page, 'detail-name'), 'E2E Created'));
                assert.equal(await text(page, 'detail-email'), created.email);

                await page.getByTestId('action-edit').click();
                await eventually(async () => assert.equal(await page.getByTestId('field-name').inputValue(), 'E2E Created'));
                await page.getByTestId('field-name').fill('E2E Renamed');
                await page.getByTestId('form-submit').click();
                await eventually(async () => assert.match(await page.getByTestId('toast-success').first().innerText(), /updated successfully/i));
                assert.equal(await page.getByTestId('field-name').inputValue(), 'E2E Renamed');
            });

            await step('đổi trạng thái sang Blocked có câu xác nhận; user bị khóa không đăng nhập được', async () => {
                await page.goBack();
                await page.getByTestId('action-status').click();
                await page.getByTestId('status-blocked').check();
                assert.equal(await text(page, 'status-confirmation'), 'Are you sure you want to block this user?');
                await page.getByTestId('confirm-status').click();
                await eventually(async () => assert.match(await page.locator('body').innerText(), /Blocked/));

                const other = await newSession(browser);
                await login(other.page, created.email, created.password);
                await eventually(async () => assert.equal(await text(other.page, 'login-error'), 'Your account has been blocked.'));
                await other.context.close();
                currentPage = page;
            });

            await step('kích hoạt lại rồi reset mật khẩu: mật khẩu cũ hết hiệu lực, mới dùng được', async () => {
                await page.getByTestId('action-status').click();
                await page.getByTestId('status-active').check();
                await page.getByTestId('confirm-status').click();
                await eventually(async () => assert.equal(await page.getByTestId('confirm-status').count(), 0));

                await page.getByTestId('action-reset').click();
                await page.getByTestId('reset-password').fill('yeu');
                await page.getByTestId('confirm-reset').click();
                await eventually(async () => assert.match(await page.locator('[role="dialog"]').innerText(), /at least 8 characters/));

                await page.getByTestId('reset-password').fill(created.newPassword);
                await page.getByTestId('reset-password-confirmation').fill(created.newPassword);
                await page.getByTestId('confirm-reset').click();
                await eventually(async () => assert.equal(await page.locator('[role="dialog"]').count(), 0));

                const other = await newSession(browser);
                await login(other.page, created.email, created.password);
                await eventually(async () => assert.equal(await text(other.page, 'login-error'), 'Invalid email or password.'));
                await login(other.page, created.email, created.newPassword);
                await other.page.waitForURL('**/cms/dashboard');
                assert.deepEqual(await menu(other.page), ['Dashboard', 'User Management', 'Profile']);
                await other.context.close();
                currentPage = page;
            });

            await step('xóa user qua hộp xác nhận: biến khỏi danh sách và không đăng nhập được nữa', async () => {
                await page.getByTestId('action-delete').click();
                assert.equal(await text(page, 'delete-target'), '"E2E Renamed"');
                await page.getByTestId('confirm-delete').click();
                await page.waitForURL(/\/cms\/users$/);

                await page.getByTestId('filter-search').fill('e2e.created');
                await page.getByTestId('filter-submit').click();
                await eventually(async () => assert.match(await text(page, 'state-empty'), /No users found/));

                const other = await newSession(browser);
                await login(other.page, created.email, created.newPassword);
                await eventually(async () => assert.equal(await text(other.page, 'login-error'), 'Invalid email or password.'));
                await other.context.close();
                currentPage = page;
            });

            await step('Audit Logs ghi đủ thao tác và không chứa mật khẩu', async () => {
                await page.getByTestId('nav-audit-logs').click();
                await eventually(async () => assert.ok((await page.locator('[data-testid^="audit-row-"]').count()) >= 6));

                await page.getByTestId('al-action').selectOption('RESET_PASSWORD');
                await page.getByTestId('al-apply').click();
                await eventually(async () => assert.equal(await page.locator('[data-testid^="audit-row-"]').count(), 1));

                const body = (await page.locator('body').innerText()) + (await page.content());

                for (const secret of [created.password, created.newPassword, PASSWORD, 'yeu']) {
                    assert.ok(!body.includes(secret), `Trang audit log lộ chuỗi ${secret}`);
                }

                await page.getByTestId('al-reset').click();
                await eventually(async () => {
                    const actions = (await page.locator('[data-testid^="audit-row-"]').allInnerTexts()).join('\n');

                    for (const action of ['LOGIN', 'CREATE_USER', 'UPDATE_USER', 'CHANGE_STATUS', 'RESET_PASSWORD', 'DELETE_USER']) {
                        assert.ok(actions.includes(action), `thiếu ${action}`);
                    }
                });
            });

            await step('không sửa/xóa được SUPERADMIN (chỉ xem): dòng không có nút hành động', async () => {
                await page.goto(`${BASE}/cms/users?role=superadmin`);
                await eventually(async () => assert.ok((await page.locator('[data-testid^="user-row-"]').count()) >= 1));

                const row = page.locator('[data-testid^="user-row-"]').first();

                assert.equal(await row.locator('[data-testid="action-view"]').count(), 1);
                assert.equal(await row.locator('[data-testid="action-delete"]').count(), 0);
                assert.equal(await row.locator('[data-testid="action-edit"]').count(), 0);
            });

            await step('Logout xóa phiên; nút Back không vào lại được trang riêng tư', async () => {
                await page.getByTestId('nav-logout').click();
                await page.waitForURL('**/cms/login');
                assert.equal(await page.evaluate(() => sessionStorage.getItem('cms.token')), null);

                await page.goto(`${BASE}/cms/users`);
                await page.waitForURL('**/cms/login**');
            });

            await context.close();
        }

        // ========================================================= ADMIN
        console.log('\nQA 2 - ADMIN');
        {
            const { context, page } = await newSession(browser);

            await step('menu chỉ có Dashboard, User Management, Profile', async () => {
                await loginAndLand(page, 'admin@example.com');
                assert.deepEqual(await menu(page), ['Dashboard', 'User Management', 'Profile']);
            });

            await step('chỉ thấy USER (27 bản ghi), không thấy ADMIN/SUPERADMIN', async () => {
                await page.getByTestId('nav-users').click();
                await eventually(async () => assert.equal(await text(page, 'pagination-summary'), 'Showing 1–20 of 27'));
                assert.deepEqual(await page.getByTestId('filter-role').locator('option').allInnerTexts(), ['All', 'User']);

                const roles = await page.locator('[data-testid="role-badge"]').allInnerTexts();

                assert.ok(roles.length > 0 && roles.every((role) => role === 'User'));
            });

            await step('tạo user: dropdown role chỉ có User', async () => {
                await page.goto(`${BASE}/cms/users/create`);
                await eventually(async () => assert.deepEqual(await page.getByTestId('field-role').locator('option').allInnerTexts(), ['User']));
            });

            await step('vào thẳng URL Audit Logs hiện trang 403 và API cũng từ chối', async () => {
                await page.goto(`${BASE}/cms/audit-logs`);
                await page.getByTestId('forbidden-page').waitFor();
                assert.equal((await apiStatus(page, '/api/admin/audit-logs')).status, 403);
            });

            await step('IDOR: id của SUPERADMIN/ADMIN bị chặn ở mọi thao tác, id của USER thì được', async () => {
                // id 2 = superadmin, id 3 = admin, id 4 = user (theo thứ tự seed)
                for (const id of [2, 3]) {
                    assert.equal((await apiStatus(page, `/api/admin/users/${id}`)).status, 403, `GET ${id}`);
                    assert.equal((await apiStatus(page, `/api/admin/users/${id}`, { method: 'PUT', body: JSON.stringify({ name: 'Hacked' }) })).status, 403, `PUT ${id}`);
                    assert.equal((await apiStatus(page, `/api/admin/users/${id}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'blocked' }) })).status, 403, `PATCH ${id}`);
                    assert.equal((await apiStatus(page, `/api/admin/users/${id}`, { method: 'DELETE' })).status, 403, `DELETE ${id}`);
                }

                assert.equal((await apiStatus(page, '/api/admin/users/4')).status, 200);
                assert.equal((await apiStatus(page, '/api/admin/users/999999')).status, 404);
            });

            await step('leo thang đặc quyền qua trường role bị chặn', async () => {
                const result = await apiStatus(page, '/api/admin/users/4', { method: 'PUT', body: JSON.stringify({ name: 'User', role: 'superadmin' }) });

                assert.equal(result.status, 403);
            });

            await step('lọc role=admin bằng API trả rỗng, không lộ dữ liệu', async () => {
                const result = await apiStatus(page, '/api/admin/users?role=admin');

                assert.equal(result.status, 200);
                assert.deepEqual(result.body.data, []);
            });

            await context.close();
        }

        // ========================================================= USER
        console.log('\nQA 3 - USER');
        {
            const { context, page } = await newSession(browser);

            await step('menu chỉ có Dashboard và Profile, dashboard không có thống kê', async () => {
                await loginAndLand(page, 'user@example.com');
                assert.deepEqual(await menu(page), ['Dashboard', 'Profile']);
                await eventually(async () => assert.equal(await page.getByTestId('me-card').count(), 1));
                assert.equal(await page.getByTestId('stat-cards').count(), 0);
            });

            await step('vào thẳng mọi URL quản trị đều hiện trang 403', async () => {
                for (const url of ['/cms/users', '/cms/users/create', '/cms/users/2', '/cms/users/2/edit', '/cms/audit-logs']) {
                    await page.goto(`${BASE}${url}`);
                    await page.getByTestId('forbidden-page').waitFor();
                }
            });

            await step('API quản trị trả 403 với token của USER, kể cả khi gọi trực tiếp', async () => {
                assert.equal((await apiStatus(page, '/api/admin/users')).status, 403);
                assert.equal((await apiStatus(page, '/api/admin/users', { method: 'POST', body: JSON.stringify({ name: 'X', email: 'x@example.com', password: 'Password@123', password_confirmation: 'Password@123', role: 'user', status: 'active' }) })).status, 403);
                assert.equal((await apiStatus(page, '/api/admin/users/3', { method: 'DELETE' })).status, 403);
                assert.equal((await apiStatus(page, '/api/admin/audit-logs')).status, 403);
                assert.equal((await apiStatus(page, '/api/admin/users/999999')).status, 403, 'không lộ id nào có thật');
            });

            await step('cập nhật hồ sơ: tên, số điện thoại; email và role không sửa được', async () => {
                await page.goto(`${BASE}/cms/profile`);
                await page.getByTestId('profile-name').waitFor();
                assert.equal(await page.getByTestId('profile-email').isDisabled(), true);

                await page.getByTestId('profile-name').fill('User Da Doi Ten');
                await page.getByTestId('profile-phone').fill('0912345678');
                await page.getByTestId('profile-save').click();
                await eventually(async () => assert.match(await page.getByTestId('toast-success').first().innerText(), /Profile updated/));
                await eventually(async () => assert.equal(await text(page, 'sidebar-user-name'), 'User Da Doi Ten'));

                const forged = await apiStatus(page, '/api/profile', { method: 'PUT', body: JSON.stringify({ name: 'User Da Doi Ten', role: 'superadmin', status: 'blocked', email: 'hacker@example.com' }) });

                assert.equal(forged.status, 200);
                assert.equal(forged.body.data.role, 'user');
                assert.equal(forged.body.data.email, 'user@example.com');
            });

            await step('tải ảnh đại diện thật (multipart), từ chối file không phải ảnh', async () => {
                const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
                const pngPath = path.join(os.tmpdir(), 'e2e-avatar.png');
                const fakePath = path.join(os.tmpdir(), 'e2e-shell.jpg');

                fs.writeFileSync(pngPath, png);
                fs.writeFileSync(fakePath, '<?php system($_GET["c"]); ?>');

                await page.goto(`${BASE}/cms/profile`);
                await page.getByTestId('profile-name').waitFor();

                // File PHP đội lốt .jpg: client chặn theo type, nhưng ép gửi lên để chứng minh backend cũng chặn theo nội dung
                const rejected = await page.evaluate(async (content) => {
                    const form = new FormData();

                    form.append('_method', 'PUT');
                    form.append('avatar', new File([content], 'shell.jpg', { type: 'image/jpeg' }));

                    const response = await fetch('/api/profile', {
                        method: 'POST',
                        headers: { Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('cms.token')}` },
                        body: form,
                    });

                    return response.status;
                }, '<?php system($_GET["c"]); ?>');

                assert.equal(rejected, 422);

                await page.getByTestId('profile-avatar').setInputFiles(pngPath);
                await page.getByTestId('profile-save').click();
                await eventually(async () => assert.match(await page.getByTestId('toast-success').first().innerText(), /Profile updated/));
                await eventually(async () => assert.match((await page.locator('aside img').first().getAttribute('src')) ?? '', /\/storage\/avatars\//));

                assert.ok(listAvatars().length > avatarsBefore.size, 'file avatar chưa được lưu');
            });

            await step('đổi mật khẩu: bị đăng xuất, mật khẩu cũ hết hiệu lực, mới dùng được', async () => {
                const changed = 'ChangedE2E@1';

                await page.goto(`${BASE}/cms/profile`);
                await page.getByTestId('cp-current').fill('sai-mat-khau-hien-tai');
                await page.getByTestId('cp-new').fill(changed);
                await page.getByTestId('cp-confirm').fill(changed);
                await page.getByTestId('cp-submit').click();
                await eventually(async () => assert.match(await page.locator('#current-password-error').innerText(), /incorrect/i));

                await page.getByTestId('cp-current').fill(PASSWORD);
                await page.getByTestId('cp-new').fill(changed);
                await page.getByTestId('cp-confirm').fill(changed);
                await page.getByTestId('cp-submit').click();

                await page.waitForURL('**/cms/login**');
                await eventually(async () => assert.match(await text(page, 'login-notice'), /password was changed/i));

                await login(page, 'user@example.com', PASSWORD);
                await eventually(async () => assert.equal(await text(page, 'login-error'), 'Invalid email or password.'));

                await login(page, 'user@example.com', changed);
                await page.waitForURL('**/cms/dashboard');
            });

            await step('token bị thu hồi giữa chừng: thao tác kế tiếp đưa về login kèm thông báo hết phiên', async () => {
                php('tinker', '--execute', "DB::table('personal_access_tokens')->delete();");

                await page.getByTestId('nav-profile').click();
                await page.waitForURL('**/cms/login**');
                await eventually(async () => assert.match(await text(page, 'login-notice'), /session has expired/i));
                assert.equal(await page.evaluate(() => sessionStorage.getItem('cms.token')), null);
            });

            await context.close();
        }

        // ========================================================= F5
        console.log('\nPhiên đăng nhập');
        {
            const { context, page } = await newSession(browser);

            await step('F5 giữ nguyên phiên và role; token sai bị từ chối', async () => {
                await loginAndLand(page, 'admin@example.com');
                await page.goto(`${BASE}/cms/users`);
                await page.getByTestId('users-table').waitFor();

                await page.reload();
                await page.getByTestId('users-table').waitFor();
                assert.deepEqual(await menu(page), ['Dashboard', 'User Management', 'Profile']);

                await page.evaluate(() => sessionStorage.setItem('cms.token', '999|token-gia-mao'));
                await page.reload();
                await page.waitForURL('**/cms/login**');
                await eventually(async () => assert.match(await text(page, 'login-notice'), /session has expired/i));
            });

            await step('trang không tồn tại hiện 404', async () => {
                await page.goto(`${BASE}/cms/khong/co/trang/nay`);
                await page.getByTestId('not-found-page').waitFor();
            });

            await context.close();
        }

        console.log('\nToàn cục');
        await step('không có lỗi CSP hay lỗi JavaScript nào trong toàn bộ phiên chạy', async () => {
            const unique = [...new Set(pageProblems)].slice(0, 6);

            assert.equal(pageProblems.length, 0, `Có ${pageProblems.length} lỗi CSP/JS. Ví dụ: ${unique.join(' | ')}`);
        });
    } finally {
        await browser.close();

        // Dọn các file avatar do E2E tạo ra
        for (const file of listAvatars()) {
            if (!avatarsBefore.has(file)) {
                fs.rmSync(path.join(AVATAR_DIR, file), { force: true });
            }
        }
    }
}

let fatal = null;

try {
    await run();
} catch (error) {
    fatal = error;
    console.error(`\nLỗi nghiêm trọng: ${error.stack ?? error}`);
} finally {
    server?.kill();

    if (process.env.E2E_KEEP !== '1') {
        fs.rmSync(DB_FILE, { force: true });
    }
}

const failed = results.filter((r) => !r.ok);

console.log(`\nKết quả E2E: ${results.length - failed.length}/${results.length} bước đạt${fatal ? ' (có lỗi nghiêm trọng)' : ''}`);

if (failed.length > 0 || fatal) {
    if (serverLog.trim()) {
        console.log(`\n--- log máy chủ (20 dòng cuối) ---\n${serverLog.trim().split('\n').slice(-20).join('\n')}`);
    }

    process.exit(1);
}
