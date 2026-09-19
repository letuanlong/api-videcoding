<?php

namespace App\Enums;

enum AuditAction: string
{
    case Login          = 'LOGIN';
    case Logout         = 'LOGOUT';

    case CreateUser     = 'CREATE_USER';
    case UpdateUser     = 'UPDATE_USER';
    case DeleteUser     = 'DELETE_USER';

    case ChangeStatus   = 'CHANGE_STATUS';
    case ResetPassword  = 'RESET_PASSWORD';

    case UpdateProfile  = 'UPDATE_PROFILE';
    case ChangePassword = 'CHANGE_PASSWORD';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $action) => $action->value, self::cases());
    }
}
