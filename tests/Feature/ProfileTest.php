<?php

use App\Actions\Auth\ChangePasswordAction;
use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function profSignIn(string $role = 'user', array $attributes = []): User
{
    $user = User::factory()->{$role}()->create($attributes);
    Sanctum::actingAs($user);

    return $user;
}

function profPasswordPayload(array $overrides = []): array
{
    return array_merge([
        'current_password'      => 'OldPassword@123',
        'password'              => 'NewPassword@123',
        'password_confirmation' => 'NewPassword@123',
    ], $overrides);
}

/** @var list<string> các file tạm tạo trong test, được xóa sau mỗi test */
$GLOBALS['profTempFiles'] = [];

afterEach(function () {
    foreach ($GLOBALS['profTempFiles'] as $file) {
        @unlink($file);
    }

    $GLOBALS['profTempFiles'] = [];
});

/**
 * Tạo UploadedFile THẬT (không phải bản fake của Laravel vốn báo MIME theo tên file) để validate
 * đọc đúng nội dung file như khi có request thực tế. Không cần extension GD.
 */
function profUpload(string $kind, string $clientName): UploadedFile
{
    // PNG 1x1 hợp lệ
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    $content = match ($kind) {
        'png'   => $png,
        'big'   => $png.str_repeat("\0", 3 * 1024 * 1024), // vẫn là PNG hợp lệ nhưng > 2MB
        'php'   => '<?php system($_GET["c"]);',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        'pdf'   => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF",
    };

    $path = tempnam(sys_get_temp_dir(), 'prof');
    file_put_contents($path, $content);
    $GLOBALS['profTempFiles'][] = $path;

    return new UploadedFile($path, $clientName, null, null, true);
}

// -----------------------------------------------------------------------------
// UMS-019: GET PROFILE
// -----------------------------------------------------------------------------
test('xem hồ sơ của chính mình đúng định dạng, role chỉ để đọc, không có mật khẩu', function (string $role) {
    $user = profSignIn($role, ['name' => 'John', 'email' => 'john@example.com', 'phone' => '0900000000']);

    $response = $this->getJson('/api/profile')
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'data'    => ['id' => $user->id, 'name' => 'John', 'email' => 'john@example.com', 'phone' => '0900000000', 'role' => $role, 'avatar' => null],
        ]);

    expect($response->getContent())->not->toContain('password')->not->toContain('$2y$');
})->with(['superadmin', 'admin', 'user']);

test('xem hồ sơ khi chưa đăng nhập trả về 401, khi bị khóa trả về 403', function () {
    $this->getJson('/api/profile')->assertStatus(401);

    profSignIn('user', ['status' => UserStatus::Blocked]);

    $this->getJson('/api/profile')->assertStatus(403);
});

// -----------------------------------------------------------------------------
// UMS-020: UPDATE PROFILE
// -----------------------------------------------------------------------------
test('cập nhật tên và số điện thoại của chính mình', function () {
    $user = profSignIn('user', ['name' => 'Cu', 'phone' => '0900000000']);

    $this->putJson('/api/profile', ['name' => 'Moi', 'phone' => '0911111111'])
        ->assertOk()
        ->assertJson(['success' => true, 'message' => 'Profile updated successfully.', 'data' => ['name' => 'Moi', 'phone' => '0911111111']]);

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('Moi')->and($fresh->phone)->toBe('0911111111');
});

test('không thể đổi role, status, email, id qua API profile: các trường đó bị bỏ qua', function () {
    $user = profSignIn('user', ['email' => 'goc@example.com']);

    $this->putJson('/api/profile', [
        'name' => 'Hop Le', 'role' => 'superadmin', 'status' => 'blocked', 'email' => 'hacker@example.com',
        'id' => 99999, 'password' => 'Hacked@12345', 'email_verified_at' => null, 'deleted_at' => '2000-01-01',
    ])->assertOk()->assertJsonPath('data.role', 'user');

    $fresh = User::find($user->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->role)->toBe(UserRole::User)
        ->and($fresh->status)->toBe(UserStatus::Active)
        ->and($fresh->email)->toBe('goc@example.com')
        ->and($fresh->name)->toBe('Hop Le')
        ->and(Hash::check('password', $fresh->getRawOriginal('password')))->toBeTrue();
});

test('ADMIN và SUPERADMIN cũng không thể tự nâng quyền qua API profile', function (string $role) {
    $user = profSignIn($role);

    $this->putJson('/api/profile', ['name' => 'X', 'role' => 'superadmin'])->assertOk();

    expect($user->fresh()->role->value)->toBe($role);
})->with(['admin', 'user']);

test('cập nhật hồ sơ ghi audit UPDATE_PROFILE chỉ chứa các trường thay đổi', function () {
    $user = profSignIn('user', ['name' => 'Ten Cu', 'phone' => '0900000000']);

    $this->putJson('/api/profile', ['name' => 'Ten Moi', 'phone' => '0900000000'])->assertOk();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe(AuditAction::UpdateProfile)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->target_id)->toBe($user->id)
        ->and($log->old_values)->toBe(['name' => 'Ten Cu'])
        ->and($log->new_values)->toBe(['name' => 'Ten Moi']);
});

test('cập nhật không thay đổi gì thì không ghi audit', function () {
    profSignIn('user', ['name' => 'Giong Nhau']);

    $this->putJson('/api/profile', ['name' => 'Giong Nhau'])->assertOk();

    expect(AuditLog::count())->toBe(0);
});

test('có thể xóa số điện thoại bằng null', function () {
    $user = profSignIn('user', ['phone' => '0900000000']);

    $this->putJson('/api/profile', ['phone' => null])->assertOk()->assertJsonPath('data.phone', null);

    expect($user->fresh()->phone)->toBeNull();
});

test('dữ liệu hồ sơ không hợp lệ bị từ chối 422', function (array $payload, string $field) {
    profSignIn();

    $this->putJson('/api/profile', $payload)
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed.'])
        ->assertJsonValidationErrors([$field]);
})->with([
    'tên rỗng'      => [['name' => ''], 'name'],
    'tên quá dài'   => [['name' => str_repeat('a', 256)], 'name'],
    'phone quá dài' => [['phone' => str_repeat('1', 21)], 'phone'],
    'avatar là chuỗi' => [['avatar' => 'khong-phai-file'], 'avatar'],
]);

// ---- avatar (multipart: PUT phải gửi bằng POST + _method=PUT) ----
test('tải ảnh đại diện lên: lưu vào disk public với tên ngẫu nhiên và trả về URL', function () {
    Storage::fake('public');
    $user = profSignIn();

    $response = $this->post('/api/profile', [
        '_method' => 'PUT',
        'avatar'  => profUpload('png', 'ten-do-client-gui.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    $path = $user->fresh()->avatar;

    expect($path)->toStartWith('avatars/')->not->toContain('ten-do-client-gui')
        ->and($response->json('data.avatar'))->toContain($path);

    Storage::disk('public')->assertExists($path);
});

test('URL ảnh đại diện theo host của request chứ không theo APP_URL (tránh ảnh hỏng và bị CSP chặn)', function () {
    Storage::fake('public');
    $user = profSignIn('user', ['avatar' => 'avatars/abc.png']);

    // APP_URL cố ý lệch với host thật của request (localhost)
    config(['app.url' => 'http://sai-host.example:9999']);

    $profileUrl = $this->getJson('/api/profile')->json('data.avatar');

    expect($profileUrl)->toBe('http://localhost/storage/avatars/abc.png')
        ->and($profileUrl)->not->toContain('sai-host');

    // Cùng quy tắc cho UserResource (danh sách/chi tiết quản trị)
    Sanctum::actingAs(User::factory()->admin()->create());

    expect($this->getJson("/api/admin/users/{$user->id}")->json('data.avatar'))->toBe('http://localhost/storage/avatars/abc.png');
});

test('không có ảnh đại diện thì avatar là null ở cả hai resource', function () {
    $user = profSignIn();

    $this->getJson('/api/profile')->assertJsonPath('data.avatar', null);

    Sanctum::actingAs(User::factory()->admin()->create());

    $this->getJson("/api/admin/users/{$user->id}")->assertJsonPath('data.avatar', null);
});

test('thay ảnh đại diện sẽ xóa ảnh cũ', function () {
    Storage::fake('public');
    $user = profSignIn();

    $upload = fn () => $this->post('/api/profile', ['_method' => 'PUT', 'avatar' => profUpload('png', 'a.png')], ['Accept' => 'application/json'])->assertOk();

    $upload();
    $first = $user->fresh()->avatar;

    $upload();
    $second = $user->fresh()->avatar;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('gửi avatar null xóa ảnh đại diện và xóa file', function () {
    Storage::fake('public');
    $user = profSignIn();
    $this->post('/api/profile', ['_method' => 'PUT', 'avatar' => profUpload('png', 'a.png')], ['Accept' => 'application/json'])->assertOk();
    $path = $user->fresh()->avatar;

    $this->putJson('/api/profile', ['avatar' => null])->assertOk()->assertJsonPath('data.avatar', null);

    expect($user->fresh()->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('tệp không phải ảnh, SVG, hoặc quá 2MB bị từ chối và không lưu file nào', function (string $kind) {
    Storage::fake('public');
    profSignIn();

    // Tên file cố ý đội lốt đuôi ảnh: hệ thống phải dựa vào NỘI DUNG file chứ không phải tên
    $file = match ($kind) {
        'pdf' => profUpload('pdf', 'cv.jpg'),
        'svg' => profUpload('svg', 'logo.png'),
        'php' => profUpload('php', 'shell.jpg'),
        'lớn' => profUpload('big', 'big.png'),
    };

    $this->post('/api/profile', ['_method' => 'PUT', 'avatar' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['avatar']);

    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['pdf', 'svg', 'php', 'lớn']);

test('ảnh đại diện được ghi vào audit log dưới dạng đường dẫn', function () {
    Storage::fake('public');
    profSignIn();

    $this->post('/api/profile', ['_method' => 'PUT', 'avatar' => profUpload('png', 'a.png')], ['Accept' => 'application/json'])->assertOk();

    expect(AuditLog::firstOrFail()->new_values['avatar'])->toStartWith('avatars/');
});

// -----------------------------------------------------------------------------
// UMS-021: CHANGE OWN PASSWORD
// -----------------------------------------------------------------------------
test('đổi mật khẩu thành công: mật khẩu mới dùng được, cũ hết hiệu lực, mọi token bị thu hồi', function () {
    $user  = User::factory()->create(['password' => Hash::make('OldPassword@123')]);
    $token = $user->createToken('device-1')->plainTextToken;
    $user->createToken('device-2');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/profile/change-password', profPasswordPayload())
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Password changed successfully. Please log in again.']);

    $hash = $user->fresh()->getRawOriginal('password');

    expect(Hash::check('NewPassword@123', $hash))->toBeTrue()
        ->and(Hash::check('OldPassword@123', $hash))->toBeFalse()
        ->and($hash)->not->toBe('NewPassword@123');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->app['auth']->forgetGuards();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPassword@123'])->assertStatus(401);
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'NewPassword@123'])->assertOk();
});

test('đổi mật khẩu ghi audit CHANGE_PASSWORD và không lưu mật khẩu', function () {
    $user = profSignIn('user', ['password' => Hash::make('OldPassword@123')]);

    $this->postJson('/api/profile/change-password', profPasswordPayload())->assertOk();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe(AuditAction::ChangePassword)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBeNull()
        ->and(json_encode($log->toArray()))->not->toContain('OldPassword@123')->not->toContain('NewPassword@123');
});

test('mật khẩu hiện tại sai trả về 422 gắn vào current_password và không thay đổi gì', function () {
    $user = User::factory()->create(['password' => Hash::make('OldPassword@123')]);
    $user->createToken('device');
    Sanctum::actingAs($user);

    $this->postJson('/api/profile/change-password', profPasswordPayload(['current_password' => 'sai-mat-khau']))
        ->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'Validation failed.',
            'errors'  => ['current_password' => ['The current password is incorrect.']],
        ]);

    expect(Hash::check('OldPassword@123', $user->fresh()->getRawOriginal('password')))->toBeTrue()
        ->and(AuditLog::count())->toBe(0);

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('mật khẩu mới không hợp lệ bị từ chối 422 và không đổi gì', function (array $override, string $field) {
    $user = profSignIn('user', ['password' => Hash::make('OldPassword@123')]);

    $this->postJson('/api/profile/change-password', profPasswordPayload($override))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    expect(Hash::check('OldPassword@123', $user->fresh()->getRawOriginal('password')))->toBeTrue();
})->with([
    'thiếu mật khẩu hiện tại'        => [['current_password' => null], 'current_password'],
    'thiếu mật khẩu mới'             => [['password' => null, 'password_confirmation' => null], 'password'],
    'xác nhận không khớp'            => [['password_confirmation' => 'Khac@12345'], 'password'],
    'quá ngắn'                       => [['password' => 'Ab@1', 'password_confirmation' => 'Ab@1'], 'password'],
    'thiếu ký tự đặc biệt'           => [['password' => 'Password123', 'password_confirmation' => 'Password123'], 'password'],
    'thiếu chữ hoa'                  => [['password' => 'password@123', 'password_confirmation' => 'password@123'], 'password'],
    'trùng mật khẩu hiện tại'        => [['password' => 'OldPassword@123', 'password_confirmation' => 'OldPassword@123'], 'password'],
]);

test('đổi mật khẩu khi chưa đăng nhập trả về 401, khi bị khóa trả về 403', function () {
    $this->postJson('/api/profile/change-password', profPasswordPayload())->assertStatus(401);

    profSignIn('user', ['status' => UserStatus::Inactive]);

    $this->postJson('/api/profile/change-password', profPasswordPayload())->assertStatus(403);
});

test('bị chặn 429 khi thử mật khẩu hiện tại sai quá 5 lần', function () {
    profSignIn('user', ['password' => Hash::make('OldPassword@123')]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/profile/change-password', profPasswordPayload(['current_password' => 'sai']))->assertStatus(422);
    }

    $this->postJson('/api/profile/change-password', profPasswordPayload(['current_password' => 'sai']))->assertStatus(429);
});

test('lỗi hệ thống bất ngờ khi đổi mật khẩu trả về 500 chung và không lộ nội dung lỗi', function () {
    config(['app.debug' => false]);
    profSignIn();

    $this->mock(ChangePasswordAction::class)
        ->shouldReceive('execute')
        ->andThrow(new RuntimeException('SQLSTATE[HY000]: thong tin noi bo'));

    $response = $this->postJson('/api/profile/change-password', profPasswordPayload());

    $response->assertStatus(500)->assertExactJson(['success' => false, 'message' => 'Server error.']);
});

test('lỗi bất ngờ khi đổi mật khẩu không làm lộ mật khẩu vào application log', function () {
    ini_set('zend.exception_ignore_args', '0');

    config([
        'logging.default'          => 'capture',
        'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
    ]);
    $handler = Log::channel('capture')->getLogger()->getHandlers()[0];

    profSignIn('user', ['password' => Hash::make('OldPassword@123')]);

    $this->mock(RecordAuditLogAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(fn () => throw new RuntimeException('audit down'));

    $this->postJson('/api/profile/change-password', profPasswordPayload())->assertStatus(500);

    $logs = collect($handler->getRecords())->map(function ($record) {
        $e = $record->context['exception'] ?? null;

        return $record->message.' '.($e instanceof Throwable ? $e->getMessage().' '.$e->getTraceAsString() : '');
    })->implode("\n");

    expect($logs)->toContain('ChangePasswordAction')
        ->and($logs)->not->toContain('OldPassword@123')
        ->and($logs)->not->toContain('NewPassword@123');
});
