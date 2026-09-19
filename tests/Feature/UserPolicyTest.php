<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function policyUser(string $role, string $status = 'active'): User
{
    return User::factory()->{$role}()->{$status}()->create();
}

// Ma trận kỳ vọng theo bảng Role Matrix của đặc tả: [actor role => [target role => được phép?]]
dataset('manage-matrix', [
    'superadmin -> superadmin' => ['superadmin', 'superadmin', false],
    'superadmin -> admin'      => ['superadmin', 'admin', true],
    'superadmin -> user'       => ['superadmin', 'user', true],
    'admin -> superadmin'      => ['admin', 'superadmin', false],
    'admin -> admin'           => ['admin', 'admin', false],
    'admin -> user'            => ['admin', 'user', true],
    'user -> superadmin'       => ['user', 'superadmin', false],
    'user -> admin'            => ['user', 'admin', false],
    'user -> user'             => ['user', 'user', false],
]);

test('update/delete/changeStatus/resetPassword theo ma trận role', function (string $actorRole, string $targetRole, bool $expected) {
    $actor  = policyUser($actorRole);
    $target = policyUser($targetRole);

    foreach (['update', 'delete', 'changeStatus', 'resetPassword'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability, $target))
            ->toBe($expected, "{$actorRole} {$ability} {$targetRole}");
    }
})->with('manage-matrix');

test('create theo ma trận role, không ai tạo được SUPERADMIN', function (string $actorRole, string $targetRole, bool $expected) {
    $actor = policyUser($actorRole);

    expect(Gate::forUser($actor)->allows('create', [User::class, UserRole::from($targetRole)]))->toBe($expected);
})->with('manage-matrix');

test('view: SUPERADMIN xem được cả 3 role, ADMIN chỉ xem USER, USER không xem được ai khác', function () {
    $superadmin = policyUser('superadmin');
    $admin      = policyUser('admin');
    $user       = policyUser('user');

    foreach ([$superadmin, policyUser('superadmin'), $admin, $user] as $target) {
        expect(Gate::forUser($superadmin)->allows('view', $target))->toBeTrue();
    }

    expect(Gate::forUser($admin)->allows('view', $user))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', policyUser('admin')))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $superadmin))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', policyUser('user')))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', $admin))->toBeFalse();
});

test('viewAny: chỉ SUPERADMIN và ADMIN', function (string $role, bool $expected) {
    expect(Gate::forUser(policyUser($role))->allows('viewAny', User::class))->toBe($expected);
})->with([
    'superadmin' => ['superadmin', true],
    'admin'      => ['admin', true],
    'user'       => ['user', false],
]);

test('không ai được xóa, khóa, sửa hay reset mật khẩu của chính mình qua API quản trị', function (string $role) {
    $actor = policyUser($role);

    foreach (['update', 'delete', 'changeStatus', 'resetPassword'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability, $actor))->toBeFalse("{$role} {$ability} chính mình");
    }
})->with(['superadmin', 'admin', 'user']);

test('SUPERADMIN không có đặc quyền vượt Policy: không quản lý được SUPERADMIN khác', function () {
    $actor = policyUser('superadmin');
    $other = policyUser('superadmin');

    foreach (['update', 'delete', 'changeStatus', 'resetPassword'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability, $other))->toBeFalse($ability);
    }
});

test('assignRole: ADMIN chỉ gán được USER, SUPERADMIN gán được ADMIN/USER, không ai gán được SUPERADMIN', function () {
    $superadmin = policyUser('superadmin');
    $admin      = policyUser('admin');
    $userTarget = policyUser('user');
    $adminTarget = policyUser('admin');

    $can = fn (User $actor, User $target, UserRole $role) => Gate::forUser($actor)->allows('assignRole', [$target, $role]);

    expect($can($admin, $userTarget, UserRole::User))->toBeTrue()
        ->and($can($admin, $userTarget, UserRole::Admin))->toBeFalse()
        ->and($can($admin, $userTarget, UserRole::SuperAdmin))->toBeFalse()
        ->and($can($superadmin, $userTarget, UserRole::Admin))->toBeTrue()
        ->and($can($superadmin, $adminTarget, UserRole::User))->toBeTrue()
        ->and($can($superadmin, $userTarget, UserRole::SuperAdmin))->toBeFalse()
        // Không quản lý được đối tượng thì không gán role được, dù role đích hợp lệ
        ->and($can($admin, $adminTarget, UserRole::User))->toBeFalse();
});

test('tài khoản inactive hoặc blocked mất toàn bộ quyền dù role cao', function (string $status) {
    $actor  = policyUser('superadmin', $status);
    $target = policyUser('user');

    foreach (['update', 'delete', 'changeStatus', 'resetPassword', 'view'] as $ability) {
        expect(Gate::forUser($actor)->allows($ability, $target))->toBeFalse($ability);
    }

    expect(Gate::forUser($actor)->allows('viewAny', User::class))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('create', [User::class, UserRole::User]))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('viewAuditLogs'))->toBeFalse();
})->with(['inactive', 'blocked']);

test('viewAuditLogs: chỉ SUPERADMIN active', function (string $role, bool $expected) {
    expect(Gate::forUser(policyUser($role))->allows('viewAuditLogs'))->toBe($expected);
})->with([
    'superadmin' => ['superadmin', true],
    'admin'      => ['admin', false],
    'user'       => ['user', false],
]);

test('Policy chặn thao tác ngay cả khi gọi trực tiếp qua Gate::authorize', function () {
    $admin      = policyUser('admin');
    $superadmin = policyUser('superadmin');

    $this->actingAs($admin);

    expect(fn () => Gate::authorize('update', $superadmin))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});
