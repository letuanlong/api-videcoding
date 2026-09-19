<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;

test('SUPERADMIN quản lý được ADMIN và USER, không quản lý được SUPERADMIN', function () {
    expect(UserRole::SuperAdmin->manageableRoles())->toBe([UserRole::Admin, UserRole::User])
        ->and(UserRole::SuperAdmin->canManage(UserRole::SuperAdmin))->toBeFalse();
});

test('ADMIN chỉ quản lý được USER', function () {
    expect(UserRole::Admin->manageableRoles())->toBe([UserRole::User])
        ->and(UserRole::Admin->canManage(UserRole::User))->toBeTrue()
        ->and(UserRole::Admin->canManage(UserRole::Admin))->toBeFalse()
        ->and(UserRole::Admin->canManage(UserRole::SuperAdmin))->toBeFalse();
});

test('USER không quản lý được ai', function () {
    expect(UserRole::User->manageableRoles())->toBe([])
        ->and(UserRole::User->manageableValues())->toBe([])
        ->and(UserRole::User->canManage(UserRole::User))->toBeFalse();
});

test('giá trị của các enum khớp đặc tả', function () {
    expect(UserRole::values())->toBe(['superadmin', 'admin', 'user'])
        ->and(UserStatus::values())->toBe(['active', 'inactive', 'blocked'])
        ->and(AuditAction::values())->toBe([
            'LOGIN', 'LOGOUT',
            'CREATE_USER', 'UPDATE_USER', 'DELETE_USER',
            'CHANGE_STATUS', 'RESET_PASSWORD',
            'UPDATE_PROFILE', 'CHANGE_PASSWORD',
        ]);
});
