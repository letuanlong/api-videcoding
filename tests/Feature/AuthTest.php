<?php
use Tests\TestCase;
use App\Actions\Auth\ChangePasswordAction;
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
// -----------------------------------------------------------------------------
// 5. TEST BỔ SUNG: LOGIN
// -----------------------------------------------------------------------------
test('đăng nhập với email không tồn tại trả về cùng lỗi 422 như sai mật khẩu', function () {
    $response = $this->postJson('/api/auth/login', [
        'email'    => 'khong-ton-tai@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    expect($response->json('errors.email.0'))->toBe('Thông tin đăng nhập không chính xác.');
});

test('đăng nhập thiếu trường bắt buộc trả về 422', function () {
    $this->postJson('/api/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

test('đăng nhập lại sẽ thu hồi token cũ, chỉ còn một token', function () {
    $user = User::factory()->create(['password' => Hash::make('password123')]);
    $credentials = ['email' => $user->email, 'password' => 'password123'];

    $first  = $this->postJson('/api/auth/login', $credentials)->json('access_token');
    $second = $this->postJson('/api/auth/login', $credentials)->json('access_token');

    $this->assertDatabaseCount('personal_access_tokens', 1);
    expect($first)->not->toBe($second);
});

// -----------------------------------------------------------------------------
// 6. TEST BỔ SUNG: LOGOUT
// -----------------------------------------------------------------------------
test('đăng xuất khi chưa đăng nhập trả về 401', function () {
    $this->postJson('/api/auth/logout')->assertStatus(401);
});

test('đăng xuất chỉ thu hồi token hiện tại, không ảnh hưởng thiết bị khác', function () {
    $user   = User::factory()->create();
    $token1 = $user->createToken('device-1')->plainTextToken;
    $user->createToken('device-2');

    $this->withHeader('Authorization', "Bearer {$token1}")
        ->postJson('/api/auth/logout')
        ->assertStatus(200);

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

// -----------------------------------------------------------------------------
// 7. TEST BỔ SUNG: CHANGE PASSWORD (TRƯỜNG HỢP LỖI)
// -----------------------------------------------------------------------------
test('đổi mật khẩu với mật khẩu hiện tại sai trả về 400 và không thay đổi gì', function () {
    $user  = User::factory()->create(['password' => Hash::make('oldpassword123')]);
    $token = $user->createToken('device-1')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/change-password', [
            'current_password'          => 'sai-mat-khau',
            'new_password'              => 'newpassword999',
            'new_password_confirmation' => 'newpassword999',
        ]);

    $response->assertStatus(400)->assertJson(['message' => 'Mật khẩu hiện tại không chính xác.']);

    expect(Hash::check('oldpassword123', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('đổi mật khẩu với dữ liệu không hợp lệ trả về 422', function (array $payload, array $errorFields) {
    $user  = User::factory()->create(['password' => Hash::make('oldpassword123')]);
    $token = $user->createToken('device-1')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/change-password', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($errorFields);

    expect(Hash::check('oldpassword123', $user->fresh()->password))->toBeTrue();
})->with([
    'thiếu tất cả'             => [[], ['current_password', 'new_password']],
    'mật khẩu mới quá ngắn'    => [['current_password' => 'oldpassword123', 'new_password' => '1234567', 'new_password_confirmation' => '1234567'], ['new_password']],
    'xác nhận không khớp'      => [['current_password' => 'oldpassword123', 'new_password' => 'newpassword999', 'new_password_confirmation' => 'khac-nhau-hoan-toan'], ['new_password']],
    'thiếu trường xác nhận'    => [['current_password' => 'oldpassword123', 'new_password' => 'newpassword999'], ['new_password']],
]);

test('đổi mật khẩu khi chưa đăng nhập trả về 401', function () {
    $this->postJson('/api/auth/change-password', [
        'current_password'          => 'a',
        'new_password'              => 'newpassword999',
        'new_password_confirmation' => 'newpassword999',
    ])->assertStatus(401);
});

// -----------------------------------------------------------------------------
// 8. TEST BỔ SUNG: REQUEST KHÔNG CÓ HEADER "Accept: application/json"
// -----------------------------------------------------------------------------
test('route cần đăng nhập trả về 401 JSON dù client không gửi Accept: application/json', function (string $method, string $uri) {
    $response = $this->call($method, $uri);

    $response->assertStatus(401);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
})->with([
    'GET /api/user'          => ['GET', '/api/user'],
    'GET /api/orders'        => ['GET', '/api/orders'],
    'POST /api/orders'       => ['POST', '/api/orders'],
    'POST /api/auth/logout'  => ['POST', '/api/auth/logout'],
]);

test('validate lỗi trả về 422 JSON dù client không gửi Accept: application/json', function () {
    $response = $this->call('POST', '/api/auth/register', []);

    $response->assertStatus(422);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

test('lỗi hệ thống bất ngờ khi đổi mật khẩu không bị biến thành 400 và không lộ nội dung lỗi', function () {
    $user  = User::factory()->create(['password' => Hash::make('oldpassword123')]);
    $token = $user->createToken('device-1')->plainTextToken;

    config(['app.debug' => false]); // mô phỏng production: APP_DEBUG=true sẽ luôn in nội dung lỗi

    $this->mock(ChangePasswordAction::class)
        ->shouldReceive('execute')
        ->andThrow(new RuntimeException('SQLSTATE[HY000]: thong tin noi bo'));

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/change-password', [
            'current_password'          => 'oldpassword123',
            'new_password'              => 'newpassword999',
            'new_password_confirmation' => 'newpassword999',
        ]);

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('SQLSTATE');
});
