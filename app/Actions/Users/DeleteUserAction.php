<?php

namespace App\Actions\Users;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteUserAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    /**
     * Xóa mềm: bản ghi được giữ lại, user không đăng nhập được và biến mất khỏi danh sách.
     */
    public function execute(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user) {
            $snapshot = [
                'name'   => $user->name,
                'email'  => $user->email,
                'phone'  => $user->phone,
                'role'   => $user->role->value,
                'status' => $user->status->value,
            ];

            $user->tokens()->delete();
            $user->delete();

            $this->audit->execute(AuditAction::DeleteUser, $actor, $user, $snapshot, null);
        });
    }
}
