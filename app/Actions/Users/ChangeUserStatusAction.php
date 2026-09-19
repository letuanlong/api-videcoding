<?php

namespace App\Actions\Users;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Enums\AuditAction;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeUserStatusAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    public function execute(User $actor, User $user, UserStatus $status): User
    {
        // Trạng thái không đổi: không ghi DB, không ghi audit
        if ($user->status === $status) {
            return $user;
        }

        return DB::transaction(function () use ($actor, $user, $status) {
            $old = $user->status;

            $user->status = $status;
            $user->save();

            // Khóa/vô hiệu hóa thì đá user ra khỏi mọi thiết bị ngay lập tức
            if (! $user->isActive()) {
                $user->tokens()->delete();
            }

            $this->audit->execute(
                AuditAction::ChangeStatus,
                $actor,
                $user,
                ['status' => $old->value],
                ['status' => $status->value],
            );

            return $user;
        });
    }
}
