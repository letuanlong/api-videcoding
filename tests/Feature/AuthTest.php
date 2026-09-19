<?php
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(TestCase::class, RefreshDatabase::class);

// -----------------------------------------------------------------------------
// 1. TEST LOGIN
// -----------------------------------------------------------------------------
test('user có thể đăng nhập thành công với thông tin chính xác', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'access_token',
            'token_type',
        ]);
});

test('user không thể đăng nhập khi sai mật khẩu', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ]);

   $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// -----------------------------------------------------------------------------
// 2. TEST RATE LIMITING (THROTTLE / CHỐNG BRUTE-FORCE)
// -----------------------------------------------------------------------------
test('bị chặn throttle 429 khi thử đăng nhập sai quá 5 lần', function () {
    // Thử đăng nhập sai 5 lần liên tiếp
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);
    }

    // Lần thứ 6 sẽ bị chặn bởi Throttle Middleware
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429);
});

// -----------------------------------------------------------------------------
// 3. TEST LOGOUT
// -----------------------------------------------------------------------------
test('user có thể đăng xuất và vô hiệu hóa token hiện tại', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    // Gọi API Logout với Bearer Token
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Đăng xuất thành công!']);

    // Xác nhận token đã bị xóa khỏi Database
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

// -----------------------------------------------------------------------------
// 4. TEST CHANGE PASSWORD & REVOKE ALL TOKENS
// -----------------------------------------------------------------------------
test('đổi mật khẩu thành công sẽ thu hồi toàn bộ token cũ', function () {
    $user = User::factory()->create([
        'password' => Hash::make('oldpassword123'),
    ]);

    // Tạo 2 token đại diện cho 2 thiết bị
    $token1 = $user->createToken('device-1')->plainTextToken;
    $user->createToken('device-2')->plainTextToken;

    $this->assertDatabaseCount('personal_access_tokens', 2);

    // Đổi mật khẩu
    $response = $this->withHeader('Authorization', "Bearer {$token1}")
        ->postJson('/api/auth/change-password', [
            'current_password' => 'oldpassword123',
            'new_password' => 'newpassword999',
            'new_password_confirmation' => 'newpassword999',
        ]);

    $response->assertStatus(200);

    // 1. Mật khẩu mới phải khớp trong DB
    expect(Hash::check('newpassword999', $user->fresh()->password))->toBeTrue();

    // 2. Tất cả token cũ đều đã bị thu hồi
    $this->assertDatabaseCount('personal_access_tokens', 0);
});