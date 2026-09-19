<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'superadmin';
    case Admin      = 'admin';
    case User       = 'user';

    /**
     * Nguồn sự thật duy nhất của ma trận quyền: role hiện tại được quản lý những role nào.
     * SUPERADMIN không tự quản lý được SUPERADMIN (kể cả chính mình).
     *
     * @return list<self>
     */
    public function manageableRoles(): array
    {
        return match ($this) {
            self::SuperAdmin => [self::Admin, self::User],
            self::Admin      => [self::User],
            self::User       => [],
        };
    }

    /**
     * Các role mà người dùng này được phép XEM. SUPERADMIN xem được cả SUPERADMIN (chỉ đọc),
     * nhưng danh sách mặc định vẫn chỉ gồm các role quản lý được.
     *
     * @return list<self>
     */
    public function viewableRoles(): array
    {
        return match ($this) {
            self::SuperAdmin => [self::SuperAdmin, self::Admin, self::User],
            self::Admin      => [self::User],
            self::User       => [],
        };
    }

    public function canView(self $target): bool
    {
        return in_array($target, $this->viewableRoles(), true);
    }

    public function canManage(self $target): bool
    {
        return in_array($target, $this->manageableRoles(), true);
    }

    /**
     * @return list<string>
     */
    public function manageableValues(): array
    {
        return array_map(fn (self $role) => $role->value, $this->manageableRoles());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
