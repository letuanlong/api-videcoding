<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function loginPayload(User $user, string $password = 'Password@123'): array
{
    return ['email' => $user->email, 'password' => $password];
}

function makeUser(string $state = 'user', array $attributes = []): User
{
    return User::factory()->{$state}()->create(['password' => Hash::make('Password@123'), ...$attributes]);
}

// -----------------------------------------------------------------------------
// UMS-004: LOGIN
// -----------------------------------------------------------------------------
test('đăng nhập đúng thông tin trả về user và token theo định dạng chuẩn', function () {
    $user = makeUser(attributes: ['name' => 'John', 'email' => 'user@example.com']);

    $response = $this->postJson('/api/login', loginPayload($user));

    $response->assertOk()
        ->assertJsonStructure(['success', 'message', 'data' => ['user' => ['id', 'name', 'email', 'role'], 'token']])
        ->assertJson([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => ['user' => ['id' => $user->id, 'name' => 'John', 'email' => 'user@example.com', 'role' => 'user']],
        ]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and(array_keys($response->json('data.user')))->toBe(['id', 'name', 'email', 'role']);
});

test('token nhận được dùng được để gọi API cần đăng nhập', function () {
    $user  = makeUser();
    $token = $this->postJson('/api/login', loginPayload($user))->json('data.token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('response đăng nhập trả về đúng role của từng loại user và không bao giờ chứa mật khẩu', function (string $state, string $role) {
    $user = makeUser($state);

    $response = $this->postJson('/api/login', loginPayload($user));

    $response->assertOk()->assertJsonPath('data.user.role', $role);

    expect($response->getContent())
        ->not->toContain('password')
        ->not->toContain($user->getRawOriginal('password'))
        ->not->toContain('Password@123');
})->with([
    'superadmin' => ['superadmin', 'superadmin'],
    'admin'      => ['admin', 'admin'],
    'user'       => ['user', 'user'],
]);

test('sai mật khẩu trả về 401', function () {
    $user = makeUser();

    $this->postJson('/api/login', loginPayload($user, 'sai-mat-khau'))
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Invalid credentials.']);
});

test('email không tồn tại trả về 401 với cùng nội dung như sai mật khẩu', function () {
    $wrongPassword = $this->postJson('/api/login', loginPayload(makeUser(), 'sai'));
    $unknownEmail  = $this->postJson('/api/login', ['email' => 'khong-co@example.com', 'password' => 'Password@123']);

    $unknownEmail->assertStatus(401);

    expect($unknownEmail->getContent())->toBe($wrongPassword->getContent());
});

test('tài khoản inactive không đăng nhập được và không có token nào được tạo', function () {
    $user = makeUser('inactive');

    $this->postJson('/api/login', loginPayload($user))
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'Your account is inactive.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect($user->fresh()->last_login_at)->toBeNull();
});

test('tài khoản blocked không đăng nhập được và nhận message riêng', function () {
    $user = makeUser('blocked');

    $this->postJson('/api/login', loginPayload($user))
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'Your account has been blocked.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('sai mật khẩu trên tài khoản bị khóa vẫn trả 401, không lộ trạng thái tài khoản', function (string $state) {
    $user = makeUser($state);

    $this->postJson('/api/login', loginPayload($user, 'sai-mat-khau'))
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Invalid credentials.']);
})->with(['inactive', 'blocked']);

test('tài khoản đã xóa mềm không đăng nhập được', function () {
    $user = makeUser();
    $user->delete();

    $this->postJson('/api/login', loginPayload($user))
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Invalid credentials.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('đăng nhập thành công cập nhật last_login_at', function () {
    $this->freezeSecond(); // cột timestamp chỉ lưu đến giây
    $user = makeUser();

    expect($user->last_login_at)->toBeNull();

    $this->postJson('/api/login', loginPayload($user))->assertOk();

    expect($user->fresh()->last_login_at->equalTo(now()))->toBeTrue();
});

test('đăng nhập thất bại không cập nhật last_login_at', function () {
    $user = makeUser();

    $this->postJson('/api/login', loginPayload($user, 'sai'))->assertStatus(401);

    expect($user->fresh()->last_login_at)->toBeNull();
});

test('đăng nhập lại thu hồi token cũ, chỉ còn một token', function () {
    $user = makeUser();

    $first  = $this->postJson('/api/login', loginPayload($user))->json('data.token');
    $second = $this->postJson('/api/login', loginPayload($user))->json('data.token');

    $this->assertDatabaseCount('personal_access_tokens', 1);
    expect($first)->not->toBe($second);
});

test('đăng nhập thành công ghi audit log LOGIN, thất bại thì không ghi', function () {
    $user = makeUser();

    $this->postJson('/api/login', loginPayload($user, 'sai'))->assertStatus(401);
    expect(AuditLog::count())->toBe(0);

    $this->postJson('/api/login', loginPayload($user))->assertOk();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe(AuditAction::Login)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->target_id)->toBe($user->id)
        ->and($log->ip_address)->not->toBeNull()
        ->and($log->new_values)->toBeNull();
});

test('audit log của đăng nhập không chứa mật khẩu hay token', function () {
    $user = makeUser();

    $token = $this->postJson('/api/login', loginPayload($user))->json('data.token');

    $raw = json_encode(AuditLog::all()->toArray());

    expect($raw)->not->toContain('Password@123')->not->toContain($token);
});

test('đăng nhập thiếu hoặc sai định dạng dữ liệu trả về 422 theo định dạng chuẩn', function (array $payload, array $fields) {
    $this->postJson('/api/login', $payload)
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed.'])
        ->assertJsonValidationErrors($fields);
})->with([
    'thiếu tất cả'      => [[], ['email', 'password']],
    'email sai định dạng' => [['email' => 'khong-phai-email', 'password' => 'x'], ['email']],
    'thiếu mật khẩu'    => [['email' => 'a@example.com'], ['password']],
]);

test('bị chặn 429 khi thử đăng nhập sai quá 5 lần', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'sai'])->assertStatus(401);
    }

    $this->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'sai'])
        ->assertStatus(429)
        ->assertJson(['success' => false])
        ->assertHeader('Retry-After');
});

test('đường dẫn cũ /api/auth/login và /api/auth/register không còn tồn tại', function () {
    $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'x'])->assertStatus(404);
    $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'Password@123'])->assertStatus(404);
    $this->postJson('/api/auth/logout')->assertStatus(404);
    $this->postJson('/api/auth/change-password')->assertStatus(404);
});

// -----------------------------------------------------------------------------
// UMS-005: LOGOUT
// -----------------------------------------------------------------------------
test('đăng xuất thành công trả về định dạng chuẩn và xóa token hiện tại', function () {
    $user  = makeUser();
    $token = $user->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/logout')
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Logout successful.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('token đã đăng xuất không dùng lại được', function () {
    $user  = makeUser();
    $token = $user->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();

    // Guard giữ user của request trước trong cùng process test, cần reset để mô phỏng request mới
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertStatus(401);
});

test('đăng xuất khi chưa đăng nhập trả về 401 theo định dạng chuẩn', function () {
    $this->postJson('/api/logout')
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
});

test('đăng xuất chỉ thu hồi token hiện tại, không ảnh hưởng thiết bị khác', function () {
    $user   = makeUser();
    $token1 = $user->createToken('device-1')->plainTextToken;
    $user->createToken('device-2');

    $this->withHeader('Authorization', "Bearer {$token1}")->postJson('/api/logout')->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('đăng xuất ghi audit log LOGOUT', function () {
    $user  = makeUser();
    $token = $user->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe(AuditAction::Logout)
        ->and($log->user_id)->toBe($user->id);
});

test('user bị khóa dù còn token vẫn tự đăng xuất được', function () {
    $user  = makeUser('blocked');
    $token = $user->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

// -----------------------------------------------------------------------------
// UMS-006: AUTHENTICATION MIDDLEWARE
// -----------------------------------------------------------------------------
test('API riêng tư từ chối request thiếu token, token sai định dạng và token không tồn tại', function (string $method, string $uri, ?string $authorization) {
    $headers = $authorization ? ['Authorization' => $authorization] : [];

    $this->withHeaders($headers)->json($method, $uri)
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
})->with([
    'GET /user không token'          => ['GET', '/api/user', null],
    'GET /orders không token'        => ['GET', '/api/orders', null],
    'POST /orders không token'       => ['POST', '/api/orders', null],
    'POST /logout không token'       => ['POST', '/api/logout', null],
    'token không tồn tại'            => ['GET', '/api/user', 'Bearer 999|khong-ton-tai'],
    'sai scheme'                     => ['GET', '/api/user', 'Basic abc'],
    'Bearer rỗng'                    => ['GET', '/api/user', 'Bearer '],
]);

test('user inactive hoặc blocked dù còn token vẫn bị chặn 403 ở API riêng tư', function (string $state, string $uri) {
    $user  = makeUser($state);
    $token = $user->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson($uri)
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'Your account is not active.']);
})->with([
    'inactive /user'   => ['inactive', '/api/user'],
    'blocked /user'    => ['blocked', '/api/user'],
    'inactive /orders' => ['inactive', '/api/orders'],
    'blocked /orders'  => ['blocked', '/api/orders'],
]);

test('user đã bị xóa mềm dù còn token vẫn bị từ chối 401', function () {
    $user  = makeUser();
    $token = $user->createToken('device')->plainTextToken;

    $user->delete();

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/user')->assertStatus(401);
});
