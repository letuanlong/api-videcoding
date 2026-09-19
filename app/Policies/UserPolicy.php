<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Nơi duy nhất quyết định ai được làm gì với user khác (UMS-008).
 *
 * Cố ý KHÔNG dùng before() cho SUPERADMIN: SUPERADMIN cũng không được xóa/khóa chính mình
 * hay quản lý SUPERADMIN khác, và nhờ vậy hệ thống không thể bị "hết quản trị viên cao nhất".
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && $actor->role->manageableRoles() !== [];
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->isActive() && $actor->role->canView($target->role);
    }

    /**
     * @param UserRole $role role của tài khoản sắp được tạo
     */
    public function create(User $actor, UserRole $role): bool
    {
        return $actor->isActive() && $actor->role->canManage($role);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    public function changeStatus(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    /**
     * Có được gán $role cho $target hay không (chống leo thang đặc quyền qua trường role).
     * ADMIN chỉ gán được USER; SUPERADMIN gán được ADMIN/USER; không ai gán được SUPERADMIN.
     */
    public function assignRole(User $actor, User $target, UserRole $role): bool
    {
        return $this->manages($actor, $target) && $actor->role->canManage($role);
    }

    /**
     * Quản lý = tài khoản đang active, không phải chính mình, và role của đối tượng nằm trong phạm vi quản lý.
     */
    private function manages(User $actor, User $target): bool
    {
        return $actor->isActive()
            && ! $actor->is($target)
            && $actor->role->canManage($target->role);
    }
}
