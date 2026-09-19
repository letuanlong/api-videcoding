<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('bảng users có đủ các cột phục vụ RBAC', function () {
    expect(Schema::hasColumns('users', [
        'id', 'name', 'email', 'phone', 'password', 'role', 'status',
        'avatar', 'last_login_at', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

test('bản ghi chèn thẳng vào DB nhận role=user và status=active mặc định', function () {
    DB::table('users')->insert(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);

    $row = DB::table('users')->where('email', 'a@example.com')->first();

    expect($row->role)->toBe('user')
        ->and($row->status)->toBe('active');
});

test('role và status không thể gán hàng loạt (chống leo thang đặc quyền)', function () {
    $user = User::create([
        'name'     => 'Hacker',
        'email'    => 'hacker@example.com',
        'password' => 'Password@123',
        'role'     => 'superadmin',
        'status'   => 'blocked',
    ]);

    $fresh = $user->fresh();

    expect($fresh->role)->toBe(UserRole::User)
        ->and($fresh->status)->toBe(UserStatus::Active);

    $fresh->update(['role' => 'superadmin']);

    expect($fresh->fresh()->role)->toBe(UserRole::User);
});

test('forceFill gán được role và status, giá trị được cast sang enum', function () {
    $user = User::factory()->create();

    $user->forceFill(['role' => UserRole::Admin, 'status' => UserStatus::Blocked])->save();

    $fresh = $user->fresh();

    expect($fresh->role)->toBe(UserRole::Admin)
        ->and($fresh->status)->toBe(UserStatus::Blocked)
        ->and($fresh->isActive())->toBeFalse();
});

test('email là duy nhất, kể cả với tài khoản đã bị xóa mềm', function () {
    $user = User::factory()->create(['email' => 'dup@example.com']);

    expect(fn () => User::factory()->create(['email' => 'dup@example.com']))
        ->toThrow(QueryException::class);

    $user->delete();

    expect(fn () => User::factory()->create(['email' => 'dup@example.com']))
        ->toThrow(QueryException::class);
});

test('xóa mềm giữ lại bản ghi nhưng ẩn khỏi truy vấn mặc định', function () {
    $user = User::factory()->create();

    $user->delete();

    expect(User::count())->toBe(0)
        ->and(User::withTrashed()->count())->toBe(1)
        ->and(User::withTrashed()->first()->deleted_at)->not->toBeNull();
});

test('mật khẩu được lưu dạng hash và không lộ khi serialize', function () {
    $user = User::create([
        'name'     => 'Hash Me',
        'email'    => 'hash@example.com',
        'password' => 'Password@123',
    ]);

    $stored = DB::table('users')->where('id', $user->id)->value('password');

    expect($stored)->not->toBe('Password@123')
        ->and(Hash::check('Password@123', $stored))->toBeTrue()
        ->and($user->toArray())->not->toHaveKey('password')
        ->and($user->toJson())->not->toContain($stored);
});

test('factory có state cho từng role và status', function () {
    expect(User::factory()->make()->role)->toBe(UserRole::User)
        ->and(User::factory()->admin()->make()->role)->toBe(UserRole::Admin)
        ->and(User::factory()->superadmin()->make()->role)->toBe(UserRole::SuperAdmin)
        ->and(User::factory()->make()->status)->toBe(UserStatus::Active)
        ->and(User::factory()->inactive()->make()->status)->toBe(UserStatus::Inactive)
        ->and(User::factory()->blocked()->make()->status)->toBe(UserStatus::Blocked);
});

test('scope visibleTo giới hạn danh sách theo role của người xem ở mức query', function () {
    $superadmin = User::factory()->superadmin()->create();
    $admin      = User::factory()->admin()->create();
    $user       = User::factory()->create();

    $ids = fn (User $actor) => User::visibleTo($actor)->pluck('id')->sort()->values()->all();

    expect($ids($superadmin))->toBe([$admin->id, $user->id])
        ->and($ids($admin))->toBe([$user->id])
        ->and($ids($user))->toBe([]);
});
