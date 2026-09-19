# vibecoding-api — User Management CMS & RBAC

REST API (Laravel 12, PHP 8.2) kèm giao diện CMS (Vue 3) để quản lý người dùng theo vai trò **SUPERADMIN / ADMIN / USER**.
Xác thực bằng Bearer token (Laravel Sanctum). Ngoài phần quản lý user còn có module đặt hàng (`/api/orders`) có từ trước.

Nội dung: [Cài đặt](#1-cài-đặt) · [Môi trường](#2-biến-môi-trường) · [Database và seeder](#3-database-và-seeder) · [Xác thực](#4-xác-thực) · [Phân quyền](#5-phân-quyền) · [API](#6-api) · [Frontend](#7-frontend-cms) · [Kiểm thử](#8-kiểm-thử) · [Kiến trúc](#9-kiến-trúc-code)

---

## 1. Cài đặt

Yêu cầu: PHP ≥ 8.2, Composer, Node.js ≥ 20, MySQL/MariaDB (hoặc SQLite).

```bash
composer install
cp .env.example .env            # Windows PowerShell: Copy-Item .env.example .env
php artisan key:generate
# chỉnh DB_* trong .env, rồi:
php artisan migrate
php artisan db:seed             # tạo 3 tài khoản mẫu (xem mục 3)
php artisan storage:link        # để ảnh đại diện truy cập được qua /storage

npm install
npm run build                   # build CMS vào public/build
```

Chạy:

```bash
php artisan serve               # API: http://127.0.0.1:8000/api   CMS: http://127.0.0.1:8000/cms
npm run dev                     # (tùy chọn) Vite dev server, hot reload khi sửa frontend
```

### Chạy bằng XAMPP (Apache) trong `htdocs`

1. Mở **XAMPP Control Panel**, bấm Start cho **Apache** và **MySQL**.
2. Làm các bước ở mục 1 một lần (`composer install`, `.env`, `migrate`, `db:seed`, `storage:link`, `npm install`, `npm run build`).
3. Mở trình duyệt:

| Việc | URL |
|---|---|
| CMS (giao diện) | `http://localhost/vibecoding-api/public/cms` |
| Gõ tắt (tự chuyển sang địa chỉ trên) | `http://localhost/vibecoding-api/cms` |
| API | `http://localhost/vibecoding-api/public/api/...` |

Đăng nhập bằng tài khoản mẫu ở mục 3 (vd. `superadmin@example.com` / `Password@123`).

- **Sửa frontend xong phải `npm run build`** thì Apache mới thấy thay đổi (Apache chỉ phục vụ file trong `public/build`). Muốn tự cập nhật khi sửa code thì chạy thêm `npm run dev` (Vite dev server).
- Frontend tự nhận đường dẫn gốc từ Laravel nên cùng một bản build chạy được ở `/vibecoding-api/public/cms` lẫn `/cms`, không cần cấu hình.
- **File `.htaccess` ở gốc dự án là bắt buộc khi để dự án trong `htdocs`:** thiếu nó thì `http://localhost/vibecoding-api/.env` (chứa `APP_KEY`, cấu hình DB), `.git/`, `vendor/`, `storage/logs/` bị Apache phục vụ trực tiếp cho bất kỳ ai truy cập được máy bạn (XAMPP mặc định lắng nghe mọi giao diện mạng). Đừng xóa file này.
- **Cách tốt hơn (tùy chọn): VirtualHost trỏ thẳng vào `public/`**, khi đó chỉ thư mục `public/` được phơi ra và có URL gọn `http://vibecoding.test/cms`. Thêm vào `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

  ```apache
  <VirtualHost *:80>
      ServerName vibecoding.test
      DocumentRoot "C:/xampp/htdocs/vibecoding-api/public"
      <Directory "C:/xampp/htdocs/vibecoding-api/public">
          AllowOverride All
          Require all granted
      </Directory>
  </VirtualHost>
  ```

  Thêm dòng `127.0.0.1 vibecoding.test` vào `C:\Windows\System32\drivers\etc\hosts` (cần quyền Administrator), rồi Restart Apache.
- **Khắc phục:** trang trắng hoặc file `/build/...` 404 → chưa `npm run build`; API 404 → kiểm tra Apache đã bật `mod_rewrite` (XAMPP mặc định đã bật); ảnh đại diện không hiện → chưa `php artisan storage:link`.

## 2. Biến môi trường

| Biến | Ý nghĩa | Ghi chú |
|---|---|---|
| `DB_*` | Kết nối database | Test dùng SQLite `:memory:` (cấu hình trong `phpunit.xml`) |
| `SANCTUM_EXPIRATION` | Thời hạn Bearer token (phút) | Mặc định **480**. Token quá hạn bị từ chối 401 |
| `APP_DEBUG` | Hiện chi tiết lỗi | **Bắt buộc `false` ở production**. Khi `true`, lỗi 500 in cả nội dung exception |
| `APP_URL` | URL gốc của ứng dụng | Dùng cho lệnh console. URL ảnh đại diện lấy theo host của request nên không phụ thuộc biến này |

## 3. Database và seeder

Migration (`database/migrations`):

- `users`: thêm `phone`, `role` (mặc định `user`), `status` (mặc định `active`), `avatar`, `last_login_at`, `deleted_at` (xóa mềm). `email` unique, **kể cả với tài khoản đã xóa mềm**.
- `audit_logs`: `user_id`, `action`, `target_type`, `target_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`. Chỉ ghi thêm, không sửa.
- Migration chạy được lên dữ liệu có sẵn (user cũ tự nhận `role=user`, `status=active`) và rollback được.

Seeder (`php artisan db:seed`, chạy lại nhiều lần an toàn, không tạo trùng, không ghi đè mật khẩu đã đổi):

| Email | Role | Mật khẩu (chỉ dùng dev) |
|---|---|---|
| `superadmin@example.com` | superadmin | `Password@123` |
| `admin@example.com` | admin | `Password@123` |
| `user@example.com` | user | `Password@123` |

## 4. Xác thực

- `POST /api/login` trả `token`. Gửi kèm mọi request sau đó: `Authorization: Bearer <token>`.
- Mỗi lần đăng nhập thu hồi token cũ (một thiết bị tại một thời điểm). Đăng nhập giới hạn 5 lần/phút.
- **Token bị thu hồi ngay** khi: đăng xuất, tài khoản bị chuyển sang `inactive`/`blocked`, bị xóa, bị admin reset mật khẩu, hoặc tự đổi mật khẩu.
- Tài khoản không `active` bị chặn 403 ở mọi API (kể cả khi còn token). Chỉ `logout` vẫn dùng được.
- Đăng nhập sai email hoặc mật khẩu đều trả cùng một lỗi 401 (không lộ email có tồn tại). Trạng thái tài khoản chỉ được tiết lộ **sau khi** mật khẩu đúng.

### Định dạng response

```jsonc
// Thành công
{ "success": true, "message": "Login successful.", "data": { ... } }
// Danh sách
{ "success": true, "data": [ ... ], "meta": { "current_page": 1, "per_page": 20, "total": 100, "last_page": 5 } }
// Lỗi
{ "success": false, "message": "Validation failed.", "errors": { "email": ["The email has already been taken."] } }
```

| Mã | Ý nghĩa | `message` |
|---|---|---|
| 401 | Chưa đăng nhập / token sai / hết hạn / sai thông tin đăng nhập | `Unauthenticated.` / `Invalid credentials.` |
| 403 | Không đủ quyền, hoặc tài khoản inactive/blocked | `You do not have permission to perform this action.` |
| 404 | Không tồn tại hoặc đã xóa mềm | `User not found.` / `Resource not found.` |
| 422 | Dữ liệu không hợp lệ (kèm `errors`) | `Validation failed.` |
| 429 | Quá số lần thử | `Too Many Attempts.` (kèm header `Retry-After`) |
| 500 | Lỗi hệ thống, không lộ chi tiết khi `APP_DEBUG=false` | `Server error.` |

## 5. Phân quyền

Backend là nơi quyết định quyền (`UserPolicy` + middleware `role`/`active`). Frontend chỉ ẩn/hiện cho tiện dùng.

| Chức năng | SUPERADMIN | ADMIN | USER |
|---|:-:|:-:|:-:|
| Đăng nhập, đăng xuất, xem dashboard | ✓ | ✓ | ✓ |
| Xem/sửa hồ sơ của mình, đổi mật khẩu của mình | ✓ | ✓ | ✓ |
| Xem danh sách user | ✓ | ✓ | ✗ |
| Tạo ADMIN | ✓ | ✗ | ✗ |
| Tạo USER | ✓ | ✓ | ✗ |
| Xem / sửa / xóa / đổi trạng thái / reset mật khẩu **ADMIN** | ✓ | ✗ | ✗ |
| Xem / sửa / xóa / đổi trạng thái / reset mật khẩu **USER** | ✓ | ✓ | ✗ |
| Xem SUPERADMIN khác (chỉ đọc) | ✓ | ✗ | ✗ |
| Xem audit log | ✓ | ✗ | ✗ |
| Tự xóa / tự khóa / tự đổi role qua API quản trị | ✗ | ✗ | ✗ |
| Tạo hoặc nâng ai đó lên SUPERADMIN | ✗ | ✗ | ✗ |

Chi tiết đáng chú ý:

- SUPERADMIN **không** có đặc quyền vượt Policy: không sửa/xóa/khóa được SUPERADMIN khác, kể cả chính mình. Vì vậy không thể xảy ra tình huống "hết quản trị viên cao nhất". Tài khoản SUPERADMIN chỉ tạo bằng seeder/lệnh.
- Danh sách mặc định của SUPERADMIN gồm ADMIN + USER. Lọc `role=superadmin` để xem (chỉ đọc) các SUPERADMIN. ADMIN lọc `role=admin|superadmin` nhận danh sách rỗng.
- Kiểm tra role/trạng thái chạy **trước** route-model-binding nên người không có quyền không dò được id nào có thật (luôn 403, không phân biệt 404).
- Truy cập bản ghi ngoài quyền trả **403** (không phải 404); `role` không nằm trong `$fillable`, chỉ được gán tường minh sau khi qua Policy (chống mass assignment).
- Mật khẩu: 8–72 ký tự, có chữ hoa, chữ thường, số và ký tự đặc biệt (áp dụng khi tạo, reset, đổi mật khẩu).

## 6. API

Tiền tố `/api`. Ký hiệu: 🔓 công khai · 🔑 cần token · 👑 chỉ SUPERADMIN · 🛡 SUPERADMIN + ADMIN.

| Method | Endpoint | Quyền | Mô tả |
|---|---|---|---|
| POST | `/login` | 🔓 | Đăng nhập, trả `user` + `token` |
| POST | `/logout` | 🔑 | Thu hồi token hiện tại |
| GET | `/dashboard` | 🔑 | Thống kê theo role (`stats` chỉ có với SUPERADMIN/ADMIN) |
| GET | `/profile` | 🔑 | Hồ sơ của mình |
| PUT | `/profile` | 🔑 | Sửa `name`, `phone`, `avatar` (mục dưới) |
| POST | `/profile/change-password` | 🔑 | Đổi mật khẩu, thu hồi mọi token |
| GET | `/admin/users` | 🛡 | Danh sách: `search`, `role`, `status`, `page`, `per_page` |
| POST | `/admin/users` | 🛡 | Tạo user |
| GET | `/admin/users/{id}` | 🛡 | Chi tiết |
| PUT | `/admin/users/{id}` | 🛡 | Cập nhật `name`, `email`, `phone`, `role`, `status` |
| PATCH | `/admin/users/{id}/status` | 🛡 | Đổi trạng thái |
| DELETE | `/admin/users/{id}` | 🛡 | Xóa mềm |
| POST | `/admin/users/{id}/reset-password` | 🛡 | Đặt lại mật khẩu, thu hồi token của user đó |
| GET | `/admin/audit-logs` | 👑 | `user`, `action`, `date_from`, `date_to`, `page`, `per_page` |
| GET/POST | `/orders` | 🔑 | Đặt hàng / danh sách đơn của mình (module có từ trước) |

### Ví dụ

```http
POST /api/login
{ "email": "user@example.com", "password": "Password@123" }
→ 200 { "success": true, "message": "Login successful.",
        "data": { "user": { "id": 1, "name": "John", "email": "user@example.com", "role": "user" }, "token": "1|abc..." } }
```

```http
POST /api/admin/users          (Authorization: Bearer <token của ADMIN/SUPERADMIN>)
{ "name": "Nguyen Van A", "email": "a@example.com", "phone": "0900000000",
  "password": "Password@123", "password_confirmation": "Password@123",
  "role": "user", "status": "active" }
→ 201 { "success": true, "message": "User created successfully.", "data": { "id": 12, "role": "user", ... } }
```

Mỗi user trong `data` có `abilities` (`update`, `delete`, `change_status`, `reset_password`) cho biết người đang xem được làm gì với bản ghi đó, để frontend ẩn/hiện nút mà không phải lặp lại ma trận quyền.

**Phân trang:** `per_page` chỉ nhận `10`, `20` (mặc định), `50`, `100`. Giá trị khác quay về 20, không báo lỗi.
**Tìm kiếm:** theo `name`, `email`, `phone` ở mức database (`LIKE`, không phân biệt hoa thường), kết hợp được với `role`, `status` và phân trang.

**Ảnh đại diện:** gửi `multipart/form-data`, ảnh JPG/PNG/WebP ≤ 2 MB, kiểm tra theo **nội dung file** (không tin tên/đuôi file; SVG bị từ chối). PHP không đọc được multipart với `PUT`, nên gửi `POST /api/profile` kèm trường `_method=PUT`. Gửi JSON `{"avatar": null}` để xóa ảnh.

**Trường bị bỏ qua:** `PUT /api/profile` chỉ nhận `name`, `phone`, `avatar`; `email`, `role`, `status`... gửi lên đều bị bỏ qua.

### Audit log

Ghi các hành động `LOGIN`, `LOGOUT`, `CREATE_USER`, `UPDATE_USER`, `DELETE_USER`, `CHANGE_STATUS`, `RESET_PASSWORD`, `UPDATE_PROFILE`, `CHANGE_PASSWORD` cùng người thực hiện, IP, user agent và giá trị cũ/mới (chỉ các trường thay đổi). Ghi trong cùng transaction với nghiệp vụ. **Không bao giờ lưu mật khẩu hay token** (mọi khóa chứa `password`, `token`, `secret` bị loại), và mật khẩu cũng được ẩn khỏi stack trace trong log ứng dụng (`#[\SensitiveParameter]`).

## 7. Frontend (CMS)

Vue 3 + Vue Router + Pinia + Tailwind 4, nằm ở `resources/js/cms`, phục vụ tại **`/cms`** (mọi đường dẫn con trả cùng một trang SPA). Chạy được ở gốc domain (`/cms`) lẫn trong thư mục con của XAMPP (`/vibecoding-api/public/cms`): Laravel ghi đường dẫn gốc thật vào `data-cms-base` / `data-api-base` của thẻ `#app` (CSP chặn script inline nên không truyền bằng biến JavaScript), bản build dùng đường dẫn tương đối, và chỉ chấp nhận đường dẫn nội bộ nên dữ liệu trong HTML không thể trỏ API sang origin khác.

- Trang: Login, Dashboard, User Management (danh sách/tạo/chi tiết/sửa + modal xóa, đổi trạng thái, reset mật khẩu), Audit Logs, Profile.
- Menu theo role: SUPERADMIN (Dashboard, User Management, Audit Logs, Profile), ADMIN (bỏ Audit Logs), USER (Dashboard, Profile). Vào thẳng URL không được phép sẽ hiện trang 403.
- Bộ lọc, trang và số dòng của danh sách nằm trong URL (F5 và nút Back giữ nguyên).
- **Lưu token:** trong `sessionStorage` (sống qua F5, mất khi đóng tab, không dùng `localStorage`). Mật khẩu không bao giờ được lưu. 401 ở bất kỳ API nào (hết hạn/bị thu hồi) tự xóa phiên và chuyển về login kèm trang đang xem.
- **Bảo mật trang:** CSP (`script-src 'self'`, `frame-ancestors 'none'`...), `X-Frame-Options`, `X-Content-Type-Options`; nội dung do người dùng nhập luôn hiển thị dạng chữ (không dùng `v-html`); chỉ chấp nhận `redirect` nội bộ sau đăng nhập (chống open redirect). CSP tự tắt khi chạy `npm run dev`.
- Token nằm trong `sessionStorage` nên XSS là rủi ro chính: giữ CSP, không thêm script bên thứ ba, không dùng `v-html` với dữ liệu người dùng.

## 8. Kiểm thử

```bash
php artisan test        # backend (Pest): 413 test
npm test                # frontend (Vitest + Vue Test Utils): 372 test
npm run test:e2e        # E2E thật: Laravel + bản build + Chrome/Edge thật, DB SQLite tạm (34 bước)
```

- **Backend:** xác thực, Policy (bảng 3 role × 3 role × các quyền), CRUD, IDOR, mass assignment, mật khẩu không lộ (response, audit log, log ứng dụng), phân trang/tìm kiếm, upload ảnh theo nội dung file. Test dùng SQLite `:memory:` nên **không kiểm được `lockForUpdate` và một số hành vi collation của MySQL**. Để chạy trên MySQL thật: tạo DB tạm rồi `DB_CONNECTION=mysql DB_DATABASE=<db_tam> php artisan test` (biến môi trường ghi đè `phpunit.xml`), xong thì xóa DB. **Không chạy test lên DB dev.**
- **Frontend:** util, store, router guard, từng component và trang, cùng kịch bản QA 3 role chạy trên toàn bộ ứng dụng (chỉ tầng mạng là giả).
- **E2E:** cần PHP trong PATH và đã cài Chrome hoặc Edge (không tải trình duyệt). Script tự build, tạo DB tạm, nạp dữ liệu, khởi động máy chủ rồi dọn dẹp. Ảnh chụp khi có bước fail nằm ở `e2e/artifacts`. Biến tùy chọn: `E2E_PORT`, `E2E_HEADED=1`. E2E chạy trên máy chủ PHP tích hợp ở **gốc domain**; cách chạy trong thư mục con của XAMPP (Apache, `.htaccess`) đã được kiểm tra thủ công bằng Chrome nhưng **chưa có test tự động**.

## 9. Kiến trúc code

Mỗi endpoint là một lớp mỏng theo mẫu:

```
routes/api.php → Controller (invokable) → FormRequest (validate + authorize)
               → Action::execute() (toàn bộ logic nghiệp vụ, trong DB::transaction khi ghi)
               → Resource (định dạng JSON)   |   Exception nghiệp vụ tự render response
```

| Thư mục | Vai trò |
|---|---|
| `app/Actions/{Auth,Users,Profile,AuditLogs,Dashboard,Orders}` | Logic nghiệp vụ |
| `app/DTOs` | Dữ liệu vào dạng `readonly class` |
| `app/Enums` | `UserRole` (nguồn duy nhất của ma trận quyền), `UserStatus`, `AuditAction` |
| `app/Policies/UserPolicy.php` | Quyết định ai được làm gì với user khác |
| `app/Http/Middleware` | `EnsureUserHasRole` (`role:`), `EnsureUserIsActive` (`active`), `CmsSecurityHeaders` |
| `app/Support` | `ApiResponse` (định dạng chuẩn), `PerPage` |
| `bootstrap/app.php` | Alias middleware, thứ tự ưu tiên middleware, chuẩn hóa mọi lỗi API |
| `resources/js/cms` | Frontend CMS (api, stores, router, components, pages, utils, test) |
| `e2e/run.mjs` | Bộ E2E trình duyệt thật |
| `.htaccess` (gốc dự án) | Chỉ dành cho XAMPP/Apache khi dự án nằm trong `htdocs`: chặn Apache phục vụ trực tiếp `.env`, `.git`, `vendor`... (xem mục 1) |

Quy ước: message trả về của module User Management bằng tiếng Anh theo đặc tả; comment trong code bằng tiếng Việt.
