<?php

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function secSignIn(string $role): User
{
    $user = User::factory()->{$role}()->create();
    Sanctum::actingAs($user);

    return $user;
}

/** Duyệt đệ quy JSON: tìm khoá "password"/"remember_token" hoặc chuỗi hash bcrypt bất kỳ. */
function secLeaks(mixed $data): array
{
    $found = [];

    array_walk_recursive($data, function ($value, $key) use (&$found) {
        if (in_array($key, ['password', 'remember_token'], true)) {
            $found[] = "key:{$key}";
        }

        if (is_string($value) && str_starts_with($value, '$2y$')) {
            $found[] = 'bcrypt-hash';
        }
    });

    return $found;
}

/** Bật bộ thu log trong bộ nhớ và trả về handler để kiểm tra sau. */
function secCaptureLogs(): TestHandler
{
    config([
        'logging.default'          => 'capture',
        'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
    ]);

    return Log::channel('capture')->getLogger()->getHandlers()[0];
}

/** Gộp message, context và stack trace của mọi record log thành một chuỗi để tìm mật khẩu. */
function secLogText(TestHandler $handler): string
{
    return collect($handler->getRecords())->map(function ($record) {
        $exception = $record->context['exception'] ?? null;

        return $record->message.' '.json_encode(array_diff_key($record->context, ['exception' => 1]))
            .' '.($exception instanceof Throwable ? $exception->getMessage().' '.$exception->getTraceAsString() : '');
    })->implode("\n");
}

// -----------------------------------------------------------------------------
// AUTHORIZATION: USER truy cập khu vực quản trị
// -----------------------------------------------------------------------------
test('USER thường bị chặn 403 trên mọi endpoint quản trị, kể cả với id có thật', function (string $method, string $uri, array $payload) {
    secSignIn('user');
    $target = User::factory()->create();

    $uri = str_replace('{id}', (string) $target->id, $uri);

    $this->json($method, $uri, $payload)
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'You do not have permission to perform this action.']);
})->with([
    'GET danh sách'   => ['GET', '/api/admin/users', []],
    'POST tạo'        => ['POST', '/api/admin/users', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'role' => 'user', 'status' => 'active']],
    'GET chi tiết'    => ['GET', '/api/admin/users/{id}', []],
    'PUT cập nhật'    => ['PUT', '/api/admin/users/{id}', ['name' => 'A']],
    'PATCH status'    => ['PATCH', '/api/admin/users/{id}/status', ['status' => 'blocked']],
    'DELETE'          => ['DELETE', '/api/admin/users/{id}', []],
    'POST reset mật khẩu' => ['POST', '/api/admin/users/{id}/reset-password', ['password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123']],
]);

test('quản trị viên bị khóa hoặc vô hiệu hóa dù còn token vẫn bị chặn 403', function (string $status) {
    $admin = User::factory()->admin()->{$status}()->create();
    $token = $admin->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/admin/users')
        ->assertStatus(403);
})->with(['inactive', 'blocked']);

// -----------------------------------------------------------------------------
// IDOR: đổi id trong URL để chạm vào bản ghi của người khác
// -----------------------------------------------------------------------------
test('IDOR: ADMIN đổi id sang ADMIN hoặc SUPERADMIN bị chặn ở mọi thao tác và không có gì bị thay đổi', function (string $targetRole) {
    secSignIn('admin');
    $target = User::factory()->{$targetRole}()->create(['name' => 'Nguyen Ban Dau', 'password' => Hash::make('Password@123')]);
    $before = $target->fresh()->getRawOriginal();

    $this->getJson("/api/admin/users/{$target->id}")->assertStatus(403);
    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Bi Sua Trai Phep'])->assertStatus(403);
    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'blocked'])->assertStatus(403);
    $this->postJson("/api/admin/users/{$target->id}/reset-password", ['password' => 'Hacked@12345', 'password_confirmation' => 'Hacked@12345'])->assertStatus(403);
    $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(403);

    expect(User::find($target->id)->getRawOriginal())->toBe($before)
        ->and(AuditLog::count())->toBe(0);
})->with(['admin', 'superadmin']);

test('IDOR: quét lần lượt các id 1..N, ADMIN chỉ chạm được bản ghi USER', function () {
    $admin = secSignIn('admin');
    User::factory()->superadmin()->create();
    User::factory()->admin()->create();
    User::factory()->count(2)->create();

    $statuses = User::orderBy('id')->get()->mapWithKeys(fn (User $u) => [
        $u->id => $this->getJson("/api/admin/users/{$u->id}")->status(),
    ]);

    foreach (User::orderBy('id')->get() as $user) {
        $expected = $user->role->value === 'user' ? 200 : 403;

        expect($statuses[$user->id])->toBe($expected, "id {$user->id} role {$user->role->value}");
    }
});

test('IDOR: id ngoài dải số nguyên hay dạng lạ không gây lỗi 500', function (string $id) {
    secSignIn('superadmin');

    $status = $this->getJson('/api/admin/users/'.$id)->status();

    expect($status)->toBeIn([404, 405]);
})->with(['0', '-1', '99999999999999999999', 'abc', '1e3', '1;drop', '%00']);

// -----------------------------------------------------------------------------
// MASS ASSIGNMENT
// -----------------------------------------------------------------------------
test('mass assignment: ADMIN gửi role=superadmin khi cập nhật bị chặn và role không đổi', function () {
    secSignIn('admin');
    $target = User::factory()->create();

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'John', 'role' => 'superadmin', 'status' => 'active'])
        ->assertStatus(403);

    expect($target->fresh()->role->value)->toBe('user');
});

test('mass assignment: trường lạ khi tạo user bị bỏ qua (id, email_verified_at, remember_token, deleted_at, last_login_at)', function () {
    secSignIn('admin');

    $response = $this->postJson('/api/admin/users', [
        'name' => 'Extra', 'email' => 'extra@example.com', 'password' => 'Password@123', 'password_confirmation' => 'Password@123',
        'role' => 'user', 'status' => 'active',
        'id' => 99999, 'email_verified_at' => '2000-01-01', 'remember_token' => 'x', 'deleted_at' => '2000-01-01',
        'last_login_at' => '2000-01-01', 'is_admin' => true,
    ])->assertCreated();

    $user = User::findOrFail($response->json('data.id'));

    expect($user->id)->not->toBe(99999)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and($user->deleted_at)->toBeNull()
        ->and($user->last_login_at)->toBeNull();
});

test('mass assignment: trường lạ khi cập nhật bị bỏ qua', function () {
    secSignIn('admin');
    $target = User::factory()->create(['email_verified_at' => null]);

    $this->putJson("/api/admin/users/{$target->id}", [
        'name' => 'Ok', 'id' => 99999, 'email_verified_at' => '2000-01-01', 'deleted_at' => '2000-01-01', 'last_login_at' => '2000-01-01',
    ])->assertOk();

    $fresh = User::find($target->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->email_verified_at)->toBeNull()
        ->and($fresh->last_login_at)->toBeNull();
});

// -----------------------------------------------------------------------------
// PASSWORD: không lộ trong response, audit log, application log
// -----------------------------------------------------------------------------
test('không endpoint nào trả về mật khẩu hay hash', function () {
    secSignIn('superadmin');
    $target = User::factory()->create();

    $responses = [
        $this->getJson('/api/admin/users'),
        $this->getJson("/api/admin/users/{$target->id}"),
        $this->postJson('/api/admin/users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'role' => 'user', 'status' => 'active']),
        $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Moi']),
        $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'inactive']),
        $this->postJson("/api/admin/users/{$target->id}/reset-password", ['password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123']),
    ];

    foreach ($responses as $i => $response) {
        expect($response->status())->toBeIn([200, 201]);
        expect(secLeaks($response->json()))->toBe([], "response #{$i}")
            ->and($response->getContent())->not->toContain('Password@123')->not->toContain('NewPassword@123');
    }
});

test('audit log không chứa mật khẩu sau khi tạo, cập nhật và reset', function () {
    secSignIn('superadmin');

    $id = $this->postJson('/api/admin/users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'role' => 'user', 'status' => 'active'])->json('data.id');
    $this->putJson("/api/admin/users/{$id}", ['name' => 'Moi'])->assertOk();
    $this->postJson("/api/admin/users/{$id}/reset-password", ['password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123'])->assertOk();

    $dump = json_encode(DB::table('audit_logs')->get()->all());

    expect(AuditLog::count())->toBe(3)
        ->and($dump)->not->toContain('Password@123')->not->toContain('NewPassword@123')->not->toContain('$2y$')->not->toContain('password');
});

test('lỗi bất ngờ khi reset mật khẩu không làm lộ mật khẩu vào application log', function () {
    ini_set('zend.exception_ignore_args', '0'); // mô phỏng môi trường ghi cả đối số vào stack trace

    $handler = secCaptureLogs();
    secSignIn('admin');
    $target = User::factory()->create();

    // Exception được ném từ bên trong ResetUserPasswordAction nên frame của nó nằm trong stack trace
    $this->mock(RecordAuditLogAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(fn () => throw new RuntimeException('audit down'));

    $this->postJson("/api/admin/users/{$target->id}/reset-password", [
        'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123',
    ])->assertStatus(500);

    $logs = secLogText($handler);

    expect($handler->getRecords())->not->toBeEmpty()
        ->and($logs)->toContain('audit down')
        ->and($logs)->toContain('ResetUserPasswordAction')
        ->and($logs)->not->toContain('NewPassword@123');

    // Giao dịch được hoàn tác: mật khẩu không đổi
    expect(Hash::check('password', $target->fresh()->getRawOriginal('password')))->toBeTrue();
});

test('lỗi bất ngờ khi đăng nhập không làm lộ mật khẩu vào application log', function () {
    ini_set('zend.exception_ignore_args', '0');

    $handler = secCaptureLogs();
    $user    = User::factory()->create(['password' => Hash::make('Password@123')]);

    $this->mock(RecordAuditLogAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(fn () => throw new RuntimeException('audit down'));

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Password@123'])->assertStatus(500);

    $logs = secLogText($handler);

    expect($logs)->toContain('LoginAction')->and($logs)->not->toContain('Password@123');
});

test('không tạo được user khi audit lỗi: giao dịch hoàn tác, không để lại user mồ côi', function () {
    secSignIn('admin');

    $this->mock(RecordAuditLogAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(fn () => throw new RuntimeException('audit down'));

    $this->postJson('/api/admin/users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'role' => 'user', 'status' => 'active'])
        ->assertStatus(500);

    expect(User::where('email', 'n@example.com')->exists())->toBeFalse();
});

test('lỗi 500 trả về message chung và không lộ nội dung exception khi tắt debug', function () {
    config(['app.debug' => false]);
    secSignIn('admin');

    $this->mock(RecordAuditLogAction::class)
        ->shouldReceive('execute')
        ->andReturnUsing(fn () => throw new RuntimeException('SQLSTATE[HY000] chi tiet noi bo'));

    $response = $this->postJson('/api/admin/users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'role' => 'user', 'status' => 'active']);

    $response->assertStatus(500)->assertExactJson(['success' => false, 'message' => 'Server error.']);
});
