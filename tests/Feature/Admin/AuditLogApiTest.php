<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function alSignIn(string $role): User
{
    $user = User::factory()->{$role}()->create();
    Sanctum::actingAs($user);

    return $user;
}

/** Tạo log trực tiếp để kiểm soát được created_at (cột này không mass-assignable). */
function alLog(AuditAction $action, ?User $actor = null, string $createdAt = '2026-09-10 10:00:00', ?array $new = null): int
{
    return DB::table('audit_logs')->insertGetId([
        'user_id'     => $actor?->id,
        'action'      => $action->value,
        'target_type' => User::class,
        'target_id'   => 1,
        'old_values'  => null,
        'new_values'  => $new ? json_encode($new) : null,
        'ip_address'  => '203.0.113.7',
        'user_agent'  => 'PestAgent/1.0',
        'created_at'  => $createdAt,
    ]);
}

function alIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

// -----------------------------------------------------------------------------
// PHÂN QUYỀN
// -----------------------------------------------------------------------------
test('SUPERADMIN xem được audit log', function () {
    alSignIn('superadmin');
    alLog(AuditAction::Login);

    $this->getJson('/api/admin/audit-logs')
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(1, 'data');
});

test('ADMIN và USER nhận 403, chưa đăng nhập nhận 401', function (string $role) {
    alSignIn($role);
    alLog(AuditAction::Login);

    $this->getJson('/api/admin/audit-logs')
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'You do not have permission to perform this action.']);
})->with(['admin', 'user']);

test('audit log yêu cầu đăng nhập', function () {
    $this->getJson('/api/admin/audit-logs')->assertStatus(401);
});

test('SUPERADMIN bị khóa dù còn token vẫn không xem được audit log', function () {
    $superadmin = User::factory()->superadmin()->blocked()->create();
    $token      = $superadmin->createToken('device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/audit-logs')->assertStatus(403);
});

// -----------------------------------------------------------------------------
// ĐỊNH DẠNG VÀ PHÂN TRANG
// -----------------------------------------------------------------------------
test('mỗi log có đúng các trường và thông tin người thực hiện', function () {
    $me = alSignIn('superadmin');
    alLog(AuditAction::UpdateUser, $me, new: ['name' => 'Moi']);

    $response = $this->getJson('/api/admin/audit-logs')->assertOk();

    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'action', 'user', 'target', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at',
    ])->and($response->json('data.0'))->toMatchArray([
        'action'     => 'UPDATE_USER',
        'user'       => ['id' => $me->id, 'name' => $me->name, 'email' => $me->email],
        'target'     => ['type' => 'User', 'id' => 1],
        'new_values' => ['name' => 'Moi'],
        'ip_address' => '203.0.113.7',
        'user_agent' => 'PestAgent/1.0',
    ]);
});

test('log không có người thực hiện hoặc đối tượng trả về null cho các khối đó', function () {
    alSignIn('superadmin');
    DB::table('audit_logs')->insert(['action' => 'LOGIN', 'created_at' => now()]);

    $this->getJson('/api/admin/audit-logs')
        ->assertOk()
        ->assertJsonPath('data.0.user', null)
        ->assertJsonPath('data.0.target', null);
});

test('người thực hiện đã bị xóa mềm vẫn hiện tên trong log', function () {
    alSignIn('superadmin');
    $gone = User::factory()->create(['name' => 'Da Nghi Viec']);
    alLog(AuditAction::Login, $gone);
    $gone->delete();

    $this->getJson('/api/admin/audit-logs')->assertJsonPath('data.0.user.name', 'Da Nghi Viec');
});

test('sắp xếp mới nhất lên đầu', function () {
    alSignIn('superadmin');
    $first  = alLog(AuditAction::Login);
    $second = alLog(AuditAction::Logout);

    expect(alIds($this->getJson('/api/admin/audit-logs')))->toBe([$second, $first]);
});

test('phân trang mặc định 20 và trả đủ meta', function () {
    alSignIn('superadmin');
    foreach (range(1, 45) as $i) {
        alLog(AuditAction::Login);
    }

    $response = $this->getJson('/api/admin/audit-logs')->assertOk();

    expect($response->json('data'))->toHaveCount(20)
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'per_page' => 20, 'total' => 45, 'last_page' => 3]);
});

test('per_page hợp lệ được áp dụng, không hợp lệ quay về 20', function () {
    alSignIn('superadmin');
    foreach (range(1, 60) as $i) {
        alLog(AuditAction::Login);
    }

    expect($this->getJson('/api/admin/audit-logs?per_page=50')->json('meta.per_page'))->toBe(50)
        ->and($this->getJson('/api/admin/audit-logs?per_page=7')->json('meta.per_page'))->toBe(20)
        ->and($this->getJson('/api/admin/audit-logs?per_page=abc')->json('meta.per_page'))->toBe(20);
});

// -----------------------------------------------------------------------------
// BỘ LỌC
// -----------------------------------------------------------------------------
test('lọc theo người thực hiện', function () {
    alSignIn('superadmin');
    $a = User::factory()->create();
    $b = User::factory()->create();
    $logA = alLog(AuditAction::Login, $a);
    alLog(AuditAction::Login, $b);

    expect(alIds($this->getJson("/api/admin/audit-logs?user={$a->id}")))->toBe([$logA]);
});

test('lọc theo action', function () {
    alSignIn('superadmin');
    alLog(AuditAction::Login);
    $delete = alLog(AuditAction::DeleteUser);

    expect(alIds($this->getJson('/api/admin/audit-logs?action=DELETE_USER')))->toBe([$delete]);
});

test('lọc theo khoảng ngày, gồm cả ngày đầu và ngày cuối', function () {
    alSignIn('superadmin');
    $before = alLog(AuditAction::Login, createdAt: '2026-09-09 23:59:59');
    $start  = alLog(AuditAction::Login, createdAt: '2026-09-10 00:00:00');
    $end    = alLog(AuditAction::Login, createdAt: '2026-09-12 23:59:59');
    $after  = alLog(AuditAction::Login, createdAt: '2026-09-13 00:00:00');

    $ids = alIds($this->getJson('/api/admin/audit-logs?date_from=2026-09-10&date_to=2026-09-12'));

    expect($ids)->toContain($start, $end)->not->toContain($before, $after);
    expect($this->getJson('/api/admin/audit-logs?date_from=2026-09-12')->json('meta.total'))->toBe(2)   // $end và $after
        ->and($this->getJson('/api/admin/audit-logs?date_to=2026-09-09')->json('meta.total'))->toBe(1);  // $before
});

test('các bộ lọc kết hợp được với nhau và với phân trang', function () {
    alSignIn('superadmin');
    $actor = User::factory()->create();
    $hit = alLog(AuditAction::Login, $actor, '2026-09-10 10:00:00');
    alLog(AuditAction::Logout, $actor, '2026-09-10 10:00:00');
    alLog(AuditAction::Login, $actor, '2026-08-01 10:00:00');
    alLog(AuditAction::Login, null, '2026-09-10 10:00:00');

    $response = $this->getJson("/api/admin/audit-logs?user={$actor->id}&action=LOGIN&date_from=2026-09-01&date_to=2026-09-30&per_page=10");

    expect(alIds($response))->toBe([$hit])
        ->and($response->json('meta.total'))->toBe(1);
});

test('bộ lọc không hợp lệ bị từ chối 422', function (string $query, string $field) {
    alSignIn('superadmin');

    $this->getJson("/api/admin/audit-logs?{$query}")
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed.'])
        ->assertJsonValidationErrors([$field]);
})->with([
    'action lạ'              => ['action=HACK', 'action'],
    'action viết thường'     => ['action=login', 'action'],
    'user không phải số'     => ['user=abc', 'user'],
    'user âm'                => ['user=-1', 'user'],
    'date_from sai định dạng' => ['date_from=10/09/2026', 'date_from'],
    'date_to sai định dạng'  => ['date_to=khong-phai-ngay', 'date_to'],
    'date_to trước date_from' => ['date_from=2026-09-12&date_to=2026-09-10', 'date_to'],
]);

// -----------------------------------------------------------------------------
// KHÔNG LỘ MẬT KHẨU
// -----------------------------------------------------------------------------
test('audit log tạo từ các thao tác thật không bao giờ lộ mật khẩu qua API', function () {
    alSignIn('superadmin');

    $id = $this->postJson('/api/admin/users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'role' => 'user', 'status' => 'active'])->json('data.id');
    $this->postJson("/api/admin/users/{$id}/reset-password", ['password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123'])->assertOk();

    $response = $this->getJson('/api/admin/audit-logs')->assertOk();

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->getContent())
        ->not->toContain('Password@123')
        ->not->toContain('NewPassword@123')
        ->not->toContain('$2y$')
        ->not->toContain('"password"');
});

test('thao tác của chính SUPERADMIN qua API cũng xuất hiện trong audit log', function () {
    $me = alSignIn('superadmin');
    $target = User::factory()->create();

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'blocked'])->assertOk();

    $this->getJson('/api/admin/audit-logs?action=CHANGE_STATUS')
        ->assertOk()
        ->assertJsonPath('data.0.user.id', $me->id)
        ->assertJsonPath('data.0.target.id', $target->id)
        ->assertJsonPath('data.0.old_values.status', 'active')
        ->assertJsonPath('data.0.new_values.status', 'blocked');

    expect(AuditLog::count())->toBe(1);
});
