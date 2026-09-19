<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function crudSignIn(string $role): User
{
    $user = User::factory()->{$role}()->create();
    Sanctum::actingAs($user);

    return $user;
}

function crudPayload(array $overrides = []): array
{
    return array_merge([
        'name'                  => 'Nguyen Van A',
        'email'                 => 'nguyenvana@example.com',
        'phone'                 => '0900000000',
        'password'              => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'                  => 'user',
        'status'                => 'active',
    ], $overrides);
}

const CRUD_FORBIDDEN = ['success' => false, 'message' => 'You do not have permission to perform this action.'];

// -----------------------------------------------------------------------------
// UMS-013: CREATE
// -----------------------------------------------------------------------------
test('SUPERADMIN tạo được ADMIN và USER', function (string $role) {
    $actor = crudSignIn('superadmin');

    $response = $this->postJson('/api/admin/users', crudPayload(['role' => $role]))
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'User created successfully.',
            'data'    => ['name' => 'Nguyen Van A', 'email' => 'nguyenvana@example.com', 'phone' => '0900000000', 'role' => $role, 'status' => 'active'],
        ]);

    $created = User::findOrFail($response->json('data.id'));

    expect($created->role->value)->toBe($role)
        ->and($created->status)->toBe(UserStatus::Active);
})->with(['admin', 'user']);

test('ADMIN tạo được USER', function () {
    crudSignIn('admin');

    $this->postJson('/api/admin/users', crudPayload())->assertCreated();

    expect(User::where('email', 'nguyenvana@example.com')->firstOrFail()->role)->toBe(UserRole::User);
});

test('ai cũng không tạo được SUPERADMIN, ADMIN không tạo được ADMIN, USER không tạo được ai', function (string $actorRole, string $newRole) {
    crudSignIn($actorRole);

    $this->postJson('/api/admin/users', crudPayload(['role' => $newRole]))
        ->assertStatus(403)
        ->assertExactJson(CRUD_FORBIDDEN);

    expect(User::where('email', 'nguyenvana@example.com')->exists())->toBeFalse();
})->with([
    'superadmin tạo superadmin' => ['superadmin', 'superadmin'],
    'admin tạo superadmin'      => ['admin', 'superadmin'],
    'admin tạo admin'           => ['admin', 'admin'],
    'user tạo user'             => ['user', 'user'],
    'user tạo admin'            => ['user', 'admin'],
]);

test('kiểm tra quyền chạy trước validate: ADMIN gửi role=admin kèm dữ liệu sai vẫn nhận 403', function () {
    crudSignIn('admin');

    $this->postJson('/api/admin/users', ['role' => 'admin'])->assertStatus(403);
});

test('mật khẩu được băm và không bao giờ có trong response', function () {
    crudSignIn('admin');

    $response = $this->postJson('/api/admin/users', crudPayload())->assertCreated();
    $stored   = User::where('email', 'nguyenvana@example.com')->firstOrFail()->getRawOriginal('password');

    expect($stored)->not->toBe('Password@123')
        ->and(Hash::check('Password@123', $stored))->toBeTrue()
        ->and($response->getContent())->not->toContain('Password@123')->not->toContain($stored)->not->toContain('"password"');
});

test('tạo user thành công ghi audit log CREATE_USER không chứa mật khẩu', function () {
    $actor = crudSignIn('superadmin');

    $id = $this->postJson('/api/admin/users', crudPayload(['role' => 'admin']))->json('data.id');

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe(AuditAction::CreateUser)
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->target_id)->toBe($id)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBe([
            'name' => 'Nguyen Van A', 'email' => 'nguyenvana@example.com', 'phone' => '0900000000',
            'role' => 'admin', 'status' => 'active',
        ])
        ->and(json_encode($log->toArray()))->not->toContain('Password@123');
});

test('email trùng bị từ chối 422, kể cả khi email thuộc tài khoản đã xóa mềm', function () {
    crudSignIn('admin');
    $existing = User::factory()->create(['email' => 'nguyenvana@example.com']);

    $this->postJson('/api/admin/users', crudPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $existing->delete();

    $this->postJson('/api/admin/users', crudPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('role hoặc status không hợp lệ bị từ chối 422', function (array $override, string $field) {
    crudSignIn('superadmin');

    $this->postJson('/api/admin/users', crudPayload($override))
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed.'])
        ->assertJsonValidationErrors([$field]);
})->with([
    'role lạ'      => [['role' => 'boss'], 'role'],
    'thiếu role'   => [['role' => null], 'role'],
    'status lạ'    => [['status' => 'deleted'], 'status'],
    'thiếu status' => [['status' => null], 'status'],
]);

test('dữ liệu tạo user không hợp lệ bị từ chối 422', function (array $override, string $field) {
    crudSignIn('superadmin');

    $this->postJson('/api/admin/users', crudPayload($override))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    expect(User::where('email', '!=', 'khong-lien-quan')->whereNot('role', 'superadmin')->count())->toBe(0);
})->with([
    'thiếu tên'                   => [['name' => null], 'name'],
    'tên quá dài'                 => [['name' => str_repeat('a', 256)], 'name'],
    'email sai định dạng'         => [['email' => 'khong-phai-email'], 'email'],
    'thiếu email'                 => [['email' => null], 'email'],
    'phone quá dài'               => [['phone' => str_repeat('1', 21)], 'phone'],
    'thiếu mật khẩu'              => [['password' => null, 'password_confirmation' => null], 'password'],
    'xác nhận mật khẩu không khớp' => [['password_confirmation' => 'Khac@12345'], 'password'],
    'mật khẩu quá ngắn'           => [['password' => 'Ab@1', 'password_confirmation' => 'Ab@1'], 'password'],
    'mật khẩu thiếu ký tự đặc biệt' => [['password' => 'Password123', 'password_confirmation' => 'Password123'], 'password'],
    'mật khẩu thiếu chữ hoa'      => [['password' => 'password@123', 'password_confirmation' => 'password@123'], 'password'],
    'mật khẩu thiếu số'           => [['password' => 'Password@abc', 'password_confirmation' => 'Password@abc'], 'password'],
    'mật khẩu vượt 72 ký tự'      => [['password' => 'Aa@1'.str_repeat('x', 70), 'password_confirmation' => 'Aa@1'.str_repeat('x', 70)], 'password'],
]);

test('phone là tùy chọn và có thể tạo user ở trạng thái inactive', function () {
    crudSignIn('admin');

    $this->postJson('/api/admin/users', crudPayload(['phone' => null, 'status' => 'inactive']))
        ->assertCreated()
        ->assertJsonPath('data.phone', null)
        ->assertJsonPath('data.status', 'inactive');
});

test('tạo user khi chưa đăng nhập trả về 401', function () {
    $this->postJson('/api/admin/users', crudPayload())->assertStatus(401);
});

// -----------------------------------------------------------------------------
// UMS-014: DETAIL
// -----------------------------------------------------------------------------
test('xem chi tiết theo phân quyền: trả đủ trường và không có mật khẩu', function (string $actorRole, string $targetRole) {
    crudSignIn($actorRole);
    $target = User::factory()->{$targetRole}()->create(['name' => 'Target', 'phone' => '0911111111']);

    $response = $this->getJson("/api/admin/users/{$target->id}")
        ->assertOk()
        ->assertJson(['success' => true, 'data' => ['id' => $target->id, 'name' => 'Target', 'phone' => '0911111111', 'role' => $targetRole]]);

    expect(array_keys($response->json('data')))->toBe([
        'id', 'name', 'email', 'phone', 'avatar', 'role', 'status',
        'last_login_at', 'created_at', 'updated_at', 'abilities',
    ])->and($response->getContent())->not->toContain('"password"')->not->toContain('$2y$');
})->with([
    'superadmin xem admin' => ['superadmin', 'admin'],
    'superadmin xem user'  => ['superadmin', 'user'],
    'admin xem user'       => ['admin', 'user'],
]);

test('ADMIN không xem được ADMIN và SUPERADMIN, USER không xem được ai', function (string $actorRole, string $targetRole) {
    crudSignIn($actorRole);
    $target = User::factory()->{$targetRole}()->create();

    $this->getJson("/api/admin/users/{$target->id}")
        ->assertStatus(403)
        ->assertExactJson(CRUD_FORBIDDEN);
})->with([
    'admin xem admin'      => ['admin', 'admin'],
    'admin xem superadmin' => ['admin', 'superadmin'],
    'user xem user'        => ['user', 'user'],
    'user xem admin'       => ['user', 'admin'],
]);

test('user không tồn tại hoặc đã xóa mềm trả về 404 với message User not found.', function () {
    crudSignIn('superadmin');
    $deleted = User::factory()->create();
    $deleted->delete();

    foreach ([999999, $deleted->id, 'abc'] as $id) {
        $this->getJson("/api/admin/users/{$id}")
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'User not found.']);
    }
});

test('USER gọi id không tồn tại vẫn nhận 403 chứ không phải 404, không lộ id nào có thật', function () {
    crudSignIn('user');
    $real = User::factory()->create();

    $this->getJson("/api/admin/users/{$real->id}")->assertStatus(403);
    $this->getJson('/api/admin/users/999999')->assertStatus(403);
});

// -----------------------------------------------------------------------------
// UMS-015: UPDATE
// -----------------------------------------------------------------------------
test('SUPERADMIN cập nhật được ADMIN và USER', function (string $targetRole) {
    crudSignIn('superadmin');
    $target = User::factory()->{$targetRole}()->create();

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Ten Moi', 'phone' => '0911111111', 'role' => $targetRole, 'status' => 'active'])
        ->assertOk()
        ->assertJson(['success' => true, 'message' => 'User updated successfully.', 'data' => ['name' => 'Ten Moi', 'phone' => '0911111111']]);

    expect($target->fresh()->name)->toBe('Ten Moi');
})->with(['admin', 'user']);

test('ADMIN cập nhật được USER', function () {
    crudSignIn('admin');
    $target = User::factory()->create();

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Nguyen Van B', 'phone' => '0911111111', 'role' => 'user', 'status' => 'active'])
        ->assertOk();

    expect($target->fresh()->name)->toBe('Nguyen Van B');
});

test('ADMIN không cập nhật được ADMIN hay SUPERADMIN, và USER không cập nhật được ai', function (string $actorRole, string $targetRole) {
    crudSignIn($actorRole);
    $target = User::factory()->{$targetRole}()->create(['name' => 'Giu Nguyen']);

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Bi Sua'])
        ->assertStatus(403)
        ->assertExactJson(CRUD_FORBIDDEN);

    expect($target->fresh()->name)->toBe('Giu Nguyen');
})->with([
    'admin sửa admin'      => ['admin', 'admin'],
    'admin sửa superadmin' => ['admin', 'superadmin'],
    'user sửa user'        => ['user', 'user'],
]);

test('không ai tự cập nhật chính mình qua API quản trị', function (string $role) {
    $me = crudSignIn($role);

    $this->putJson("/api/admin/users/{$me->id}", ['name' => 'Tu Sua'])->assertStatus(403);
})->with(['superadmin', 'admin']);

test('chống leo thang đặc quyền: ADMIN không đổi được role của USER lên ADMIN hay SUPERADMIN', function (string $newRole) {
    crudSignIn('admin');
    $target = User::factory()->create();

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'X', 'role' => $newRole])
        ->assertStatus(403)
        ->assertExactJson(CRUD_FORBIDDEN);

    expect($target->fresh()->role)->toBe(UserRole::User);
})->with(['admin', 'superadmin']);

test('SUPERADMIN thăng USER lên ADMIN và hạ ADMIN xuống USER, nhưng không thăng lên SUPERADMIN', function () {
    crudSignIn('superadmin');
    $user  = User::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->putJson("/api/admin/users/{$user->id}", ['name' => $user->name, 'role' => 'admin'])->assertOk();
    $this->putJson("/api/admin/users/{$admin->id}", ['name' => $admin->name, 'role' => 'user'])->assertOk();
    $this->putJson("/api/admin/users/{$user->id}", ['name' => $user->name, 'role' => 'superadmin'])->assertStatus(403);

    expect($user->fresh()->role)->toBe(UserRole::Admin)
        ->and($admin->fresh()->role)->toBe(UserRole::User);
});

test('email: giữ nguyên email của chính mình thì hợp lệ, trùng email người khác bị 422', function () {
    crudSignIn('admin');
    $target = User::factory()->create(['email' => 'keep@example.com']);
    User::factory()->create(['email' => 'taken@example.com']);

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'A', 'email' => 'keep@example.com'])->assertOk();

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'A', 'email' => 'taken@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'A', 'email' => 'brand.new@example.com'])->assertOk();

    expect($target->fresh()->email)->toBe('brand.new@example.com');
});

test('cập nhật có thể xóa số điện thoại và chỉ đổi những trường được gửi', function () {
    crudSignIn('admin');
    $target = User::factory()->create(['email' => 'keep@example.com', 'phone' => '0900000000']);

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Chi Doi Ten', 'phone' => null])->assertOk();

    $fresh = $target->fresh();

    expect($fresh->phone)->toBeNull()
        ->and($fresh->email)->toBe('keep@example.com')
        ->and($fresh->role)->toBe(UserRole::User);
});

test('cập nhật ghi audit log UPDATE_USER chỉ chứa các trường thực sự thay đổi', function () {
    $actor  = crudSignIn('admin');
    $target = User::factory()->create(['name' => 'Ten Cu', 'phone' => '0900000000']);

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Ten Moi', 'phone' => '0900000000', 'status' => 'inactive'])->assertOk();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe(AuditAction::UpdateUser)
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->target_id)->toBe($target->id)
        ->and($log->old_values)->toBe(['name' => 'Ten Cu', 'status' => 'active'])
        ->and($log->new_values)->toBe(['name' => 'Ten Moi', 'status' => 'inactive']);
});

test('cập nhật không thay đổi gì thì không ghi audit', function () {
    crudSignIn('admin');
    $target = User::factory()->create(['name' => 'Giong Nhau']);

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'Giong Nhau'])->assertOk();

    expect(AuditLog::count())->toBe(0);
});

test('dữ liệu cập nhật không hợp lệ bị từ chối 422', function (array $payload, string $field) {
    crudSignIn('admin');
    $target = User::factory()->create();

    $this->putJson("/api/admin/users/{$target->id}", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'thiếu tên'     => [[], 'name'],
    'email sai'     => [['name' => 'A', 'email' => 'khong-phai-email'], 'email'],
    'role lạ'       => [['name' => 'A', 'role' => 'boss'], 'role'],
    'status lạ'     => [['name' => 'A', 'status' => 'deleted'], 'status'],
    'phone quá dài' => [['name' => 'A', 'phone' => str_repeat('1', 21)], 'phone'],
]);

test('không thể đổi mật khẩu qua endpoint cập nhật', function () {
    crudSignIn('admin');
    $target = User::factory()->create(['password' => Hash::make('Password@123')]);

    $this->putJson("/api/admin/users/{$target->id}", ['name' => 'A', 'password' => 'Hacked@12345'])->assertOk();

    expect(Hash::check('Password@123', $target->fresh()->getRawOriginal('password')))->toBeTrue();
});

// -----------------------------------------------------------------------------
// UMS-016: CHANGE STATUS
// -----------------------------------------------------------------------------
test('ADMIN đổi trạng thái USER và ghi audit CHANGE_STATUS', function (string $status) {
    $actor  = crudSignIn('admin');
    $target = User::factory()->create();

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => $status])
        ->assertOk()
        ->assertJson(['success' => true, 'message' => 'User status updated successfully.', 'data' => ['status' => $status]]);

    $log = AuditLog::firstOrFail();

    expect($target->fresh()->status->value)->toBe($status)
        ->and($log->action)->toBe(AuditAction::ChangeStatus)
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->old_values)->toBe(['status' => 'active'])
        ->and($log->new_values)->toBe(['status' => $status]);
})->with(['inactive', 'blocked']);

test('SUPERADMIN đổi trạng thái ADMIN', function () {
    crudSignIn('superadmin');
    $target = User::factory()->admin()->create();

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'blocked'])->assertOk();

    expect($target->fresh()->status)->toBe(UserStatus::Blocked);
});

test('không đổi được trạng thái ngoài phạm vi quyền, kể cả của chính mình', function (string $actorRole, string $targetRole, bool $self) {
    $actor  = crudSignIn($actorRole);
    $target = $self ? $actor : User::factory()->{$targetRole}()->create();

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'blocked'])
        ->assertStatus(403)
        ->assertExactJson(CRUD_FORBIDDEN);

    expect($target->fresh()->status)->toBe(UserStatus::Active);
})->with([
    'admin khóa admin'           => ['admin', 'admin', false],
    'admin khóa superadmin'      => ['admin', 'superadmin', false],
    'superadmin khóa superadmin' => ['superadmin', 'superadmin', false],
    'superadmin tự khóa mình'    => ['superadmin', 'superadmin', true],
    'admin tự khóa mình'         => ['admin', 'admin', true],
    'user khóa user'             => ['user', 'user', false],
]);

test('trạng thái không hợp lệ hoặc thiếu bị từ chối 422', function (array $payload) {
    crudSignIn('admin');
    $target = User::factory()->create();

    $this->patchJson("/api/admin/users/{$target->id}/status", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
})->with([
    'thiếu'  => [[]],
    'lạ'     => [['status' => 'banned']],
    'rỗng'   => [['status' => '']],
]);

test('đổi sang trạng thái hiện tại thì không ghi audit', function () {
    crudSignIn('admin');
    $target = User::factory()->create();

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'active'])->assertOk();

    expect(AuditLog::count())->toBe(0);
});

test('khóa hoặc vô hiệu hóa user thu hồi ngay mọi token, kích hoạt lại thì không đụng token', function () {
    crudSignIn('admin');
    $target = User::factory()->create();
    $target->createToken('device-1');
    $target->createToken('device-2');

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'blocked'])->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $target->createToken('device-3');

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => 'active'])->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('user bị khóa hoặc vô hiệu hóa không thể đăng nhập', function (string $status, string $message) {
    crudSignIn('admin');
    $target = User::factory()->create(['password' => Hash::make('Password@123')]);

    $this->patchJson("/api/admin/users/{$target->id}/status", ['status' => $status])->assertOk();
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/login', ['email' => $target->email, 'password' => 'Password@123'])
        ->assertStatus(403)
        ->assertJson(['message' => $message]);
})->with([
    'inactive' => ['inactive', 'Your account is inactive.'],
    'blocked'  => ['blocked', 'Your account has been blocked.'],
]);

// -----------------------------------------------------------------------------
// UMS-017: DELETE
// -----------------------------------------------------------------------------
test('ADMIN xóa mềm USER: bản ghi còn nguyên, biến khỏi danh sách, token bị thu hồi, có audit', function () {
    $actor  = crudSignIn('admin');
    $target = User::factory()->create(['name' => 'Bi Xoa', 'phone' => '0900000000']);
    $target->createToken('device');

    $this->deleteJson("/api/admin/users/{$target->id}")
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'User deleted successfully.']);

    $log = AuditLog::firstOrFail();

    expect(User::find($target->id))->toBeNull()
        ->and(User::withTrashed()->find($target->id)->deleted_at)->not->toBeNull()
        ->and($log->action)->toBe(AuditAction::DeleteUser)
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->target_id)->toBe($target->id)
        ->and($log->old_values)->toBe(['name' => 'Bi Xoa', 'email' => $target->email, 'phone' => '0900000000', 'role' => 'user', 'status' => 'active'])
        ->and($log->new_values)->toBeNull();

    $this->assertDatabaseCount('personal_access_tokens', 0);

    expect(collect($this->getJson('/api/admin/users')->json('data'))->pluck('id')->all())->not->toContain($target->id);
});

test('SUPERADMIN xóa được ADMIN và USER', function (string $targetRole) {
    crudSignIn('superadmin');
    $target = User::factory()->{$targetRole}()->create();

    $this->deleteJson("/api/admin/users/{$target->id}")->assertOk();

    expect(User::find($target->id))->toBeNull();
})->with(['admin', 'user']);

test('user đã bị xóa không đăng nhập được', function () {
    crudSignIn('admin');
    $target = User::factory()->create(['password' => Hash::make('Password@123')]);

    $this->deleteJson("/api/admin/users/{$target->id}")->assertOk();
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/login', ['email' => $target->email, 'password' => 'Password@123'])->assertStatus(401);
});

test('không xóa được ngoài phạm vi quyền, kể cả chính mình', function (string $actorRole, string $targetRole, bool $self) {
    $actor  = crudSignIn($actorRole);
    $target = $self ? $actor : User::factory()->{$targetRole}()->create();

    $this->deleteJson("/api/admin/users/{$target->id}")
        ->assertStatus(403)
        ->assertExactJson(CRUD_FORBIDDEN);

    expect(User::find($target->id))->not->toBeNull()
        ->and(AuditLog::count())->toBe(0);
})->with([
    'admin xóa admin'           => ['admin', 'admin', false],
    'admin xóa superadmin'      => ['admin', 'superadmin', false],
    'superadmin xóa superadmin' => ['superadmin', 'superadmin', false],
    'superadmin tự xóa mình'    => ['superadmin', 'superadmin', true],
    'admin tự xóa mình'         => ['admin', 'admin', true],
    'user xóa user'             => ['user', 'user', false],
]);

test('xóa user không tồn tại hoặc đã xóa rồi trả về 404', function () {
    crudSignIn('admin');
    $target = User::factory()->create();

    $this->deleteJson("/api/admin/users/{$target->id}")->assertOk();
    $this->deleteJson("/api/admin/users/{$target->id}")->assertStatus(404)->assertJson(['message' => 'User not found.']);
    $this->deleteJson('/api/admin/users/999999')->assertStatus(404);
});

// -----------------------------------------------------------------------------
// UMS-018: RESET PASSWORD
// -----------------------------------------------------------------------------
test('ADMIN reset mật khẩu USER: mật khẩu mới dùng được, cũ hết hiệu lực, token bị thu hồi', function () {
    crudSignIn('admin');
    $target = User::factory()->create(['password' => Hash::make('OldPassword@1')]);
    $target->createToken('device');

    $this->postJson("/api/admin/users/{$target->id}/reset-password", [
        'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123',
    ])->assertOk()->assertExactJson(['success' => true, 'message' => 'Password reset successfully.']);

    $hash = $target->fresh()->getRawOriginal('password');

    expect(Hash::check('NewPassword@123', $hash))->toBeTrue()
        ->and(Hash::check('OldPassword@1', $hash))->toBeFalse()
        ->and($hash)->not->toBe('NewPassword@123');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->app['auth']->forgetGuards();

    $this->postJson('/api/login', ['email' => $target->email, 'password' => 'NewPassword@123'])->assertOk();
});

test('SUPERADMIN reset được mật khẩu ADMIN', function () {
    crudSignIn('superadmin');
    $target = User::factory()->admin()->create();

    $this->postJson("/api/admin/users/{$target->id}/reset-password", [
        'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123',
    ])->assertOk();

    expect(Hash::check('NewPassword@123', $target->fresh()->getRawOriginal('password')))->toBeTrue();
});

test('audit RESET_PASSWORD ghi việc reset nhưng không lưu mật khẩu', function () {
    $actor  = crudSignIn('admin');
    $target = User::factory()->create();

    $this->postJson("/api/admin/users/{$target->id}/reset-password", [
        'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123',
    ])->assertOk();

    $log = AuditLog::firstOrFail();

    expect($log->action)->toBe(AuditAction::ResetPassword)
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->target_id)->toBe($target->id)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBeNull()
        ->and(json_encode($log->toArray()))->not->toContain('NewPassword@123');
});

test('không reset được mật khẩu ngoài phạm vi quyền, kể cả của chính mình', function (string $actorRole, string $targetRole, bool $self) {
    $actor  = crudSignIn($actorRole);
    $target = $self ? $actor : User::factory()->{$targetRole}()->create(['password' => Hash::make('Password@123')]);
    $before = $target->fresh()->getRawOriginal('password');

    $this->postJson("/api/admin/users/{$target->id}/reset-password", [
        'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123',
    ])->assertStatus(403)->assertExactJson(CRUD_FORBIDDEN);

    expect($target->fresh()->getRawOriginal('password'))->toBe($before);
})->with([
    'admin reset admin'           => ['admin', 'admin', false],
    'admin reset superadmin'      => ['admin', 'superadmin', false],
    'superadmin reset superadmin' => ['superadmin', 'superadmin', false],
    'admin tự reset mình'         => ['admin', 'admin', true],
    'user reset user'             => ['user', 'user', false],
]);

test('reset mật khẩu với dữ liệu không hợp lệ bị từ chối 422 và không đổi gì', function (array $payload) {
    crudSignIn('admin');
    $target = User::factory()->create(['password' => Hash::make('Password@123')]);

    $this->postJson("/api/admin/users/{$target->id}/reset-password", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);

    expect(Hash::check('Password@123', $target->fresh()->getRawOriginal('password')))->toBeTrue();
})->with([
    'thiếu'                => [[]],
    'xác nhận không khớp'  => [['password' => 'NewPassword@123', 'password_confirmation' => 'Khac@12345']],
    'thiếu xác nhận'       => [['password' => 'NewPassword@123']],
    'quá yếu'              => [['password' => '12345678', 'password_confirmation' => '12345678']],
    'quá dài'              => [['password' => 'Aa@1'.'x'.str_repeat('y', 70), 'password_confirmation' => 'Aa@1'.'x'.str_repeat('y', 70)]],
]);
