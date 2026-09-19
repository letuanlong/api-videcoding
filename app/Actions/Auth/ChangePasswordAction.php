<?php
namespace App\Actions\Auth;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Enums\AuditAction;
use App\Exceptions\InvalidCurrentPasswordException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ChangePasswordAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    /**
     * Kiểm tra mật khẩu hiện tại rồi đặt mật khẩu mới.
     *
     * @throws InvalidCurrentPasswordException
     */
    public function execute(
        User $user,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): void {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCurrentPasswordException();
        }

        DB::transaction(function () use ($user, $newPassword) {
            // Cast 'hashed' tự băm mật khẩu khi gán
            $user->password = $newPassword;
            $user->save();

            // Thu hồi toàn bộ token: mật khẩu cũ và mọi phiên đăng nhập cũ đều hết hiệu lực
            $user->tokens()->delete();

            $this->audit->execute(AuditAction::ChangePassword, $user, $user);
        });
    }
}
