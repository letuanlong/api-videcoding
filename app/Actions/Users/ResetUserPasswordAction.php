<?php

namespace App\Actions\Users;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetUserPasswordAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    // #[SensitiveParameter]: giá trị mật khẩu bị ẩn khỏi stack trace nếu có exception được ghi vào log
    public function execute(User $actor, User $user, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($actor, $user, $password) {
            // Cast 'hashed' tự băm mật khẩu
            $user->password = $password;
            $user->save();

            // Mật khẩu đã bị người khác đặt lại nên mọi phiên đang mở của user phải bị thu hồi
            $user->tokens()->delete();

            // Audit chỉ ghi việc reset đã xảy ra, tuyệt đối không kèm mật khẩu
            $this->audit->execute(AuditAction::ResetPassword, $actor, $user);
        });
    }
}
