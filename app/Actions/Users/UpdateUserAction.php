<?php

namespace App\Actions\Users;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\DTOs\UpdateUserData;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateUserAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    public function execute(User $actor, User $user, UpdateUserData $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data) {
            $user->fill($data->attributes);

            if ($data->role !== null) {
                $user->role = $data->role;
            }

            if ($data->status !== null) {
                $user->status = $data->status;
            }

            $changes = $user->getDirty();

            // Không có gì thay đổi thì không ghi DB và không ghi audit
            if ($changes === []) {
                return $user;
            }

            $original = array_intersect_key($user->getRawOriginal(), $changes);

            $user->save();

            // Tài khoản vừa bị vô hiệu hóa thì thu hồi token, không cho dùng tiếp phiên đang mở
            if (array_key_exists('status', $changes) && ! $user->isActive()) {
                $user->tokens()->delete();
            }

            $this->audit->execute(AuditAction::UpdateUser, $actor, $user, $original, $changes);

            return $user;
        });
    }
}
