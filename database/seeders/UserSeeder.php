<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Mật khẩu chỉ dành cho môi trường dev/test.
     */
    private const DEV_PASSWORD = 'Password@123';

    /**
     * Tạo 3 tài khoản mẫu. An toàn khi chạy lại: khớp theo email, không tạo trùng.
     */
    public function run(): void
    {
        $accounts = [
            ['name' => 'Super Admin', 'email' => 'superadmin@example.com', 'role' => UserRole::SuperAdmin],
            ['name' => 'Admin',       'email' => 'admin@example.com',      'role' => UserRole::Admin],
            ['name' => 'User',        'email' => 'user@example.com',       'role' => UserRole::User],
        ];

        foreach ($accounts as $account) {
            // withTrashed: email unique tính cả tài khoản đã xóa mềm, nên phải khôi phục thay vì tạo mới
            $user = User::withTrashed()->firstOrNew(['email' => $account['email']]);

            if ($user->trashed()) {
                $user->restore();
            }

            // Dùng forceFill vì role/status cố ý không mass-assignable
            $user->forceFill([
                'name'   => $account['name'],
                'role'   => $account['role'],
                'status' => UserStatus::Active,
            ]);

            // Chỉ đặt mật khẩu khi tạo mới, chạy lại không ghi đè mật khẩu đã đổi (cast 'hashed' tự băm)
            if (! $user->exists) {
                $user->password = self::DEV_PASSWORD;
            }

            $user->save();
        }
    }
}
