<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('seeder tạo đúng 3 tài khoản mẫu với role và status theo đặc tả', function () {
    $this->seed(UserSeeder::class);

    expect(User::count())->toBe(3);

    foreach ([
        'superadmin@example.com' => UserRole::SuperAdmin,
        'admin@example.com'      => UserRole::Admin,
        'user@example.com'       => UserRole::User,
    ] as $email => $role) {
        $user = User::where('email', $email)->firstOrFail();

        expect($user->role)->toBe($role)
            ->and($user->status)->toBe(UserStatus::Active);
    }
});

test('mật khẩu tài khoản mẫu được hash', function () {
    $this->seed(UserSeeder::class);

    $user = User::where('email', 'admin@example.com')->firstOrFail();

    expect($user->getRawOriginal('password'))->not->toBe('Password@123')
        ->and(Hash::check('Password@123', $user->getRawOriginal('password')))->toBeTrue();
});

test('chạy seeder nhiều lần không tạo tài khoản trùng', function () {
    $this->seed(UserSeeder::class);
    $this->seed(UserSeeder::class);

    expect(User::withTrashed()->count())->toBe(3);
});

test('chạy lại seeder không ghi đè mật khẩu đã đổi nhưng đưa role và status về đúng', function () {
    $this->seed(UserSeeder::class);

    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    $admin->forceFill(['password' => 'MyNewPassword@1', 'role' => UserRole::User, 'status' => UserStatus::Blocked])->save();

    $this->seed(UserSeeder::class);

    $admin = $admin->fresh();

    expect(Hash::check('MyNewPassword@1', $admin->getRawOriginal('password')))->toBeTrue()
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->status)->toBe(UserStatus::Active);
});

test('tài khoản mẫu đã bị xóa mềm được khôi phục thay vì gây lỗi trùng email', function () {
    $this->seed(UserSeeder::class);

    User::where('email', 'user@example.com')->firstOrFail()->delete();

    $this->seed(UserSeeder::class);

    expect(User::where('email', 'user@example.com')->exists())->toBeTrue()
        ->and(User::withTrashed()->count())->toBe(3);
});

test('DatabaseSeeder chạy lại nhiều lần vẫn an toàn', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    // test@example.com + 3 tài khoản mẫu
    expect(User::withTrashed()->count())->toBe(4);
});
