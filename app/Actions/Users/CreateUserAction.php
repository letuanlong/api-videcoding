<?php

namespace App\Actions\Users;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\DTOs\CreateUserData;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateUserAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    public function execute(User $actor, CreateUserData $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            // Mật khẩu được cast 'hashed' tự băm khi gán
            $user = new User([
                'name'     => $data->name,
                'email'    => $data->email,
                'phone'    => $data->phone,
                'password' => $data->password,
            ]);

            // role/status không mass-assignable: gán tường minh sau khi đã qua UserPolicy ở tầng request
            $user->role   = $data->role;
            $user->status = $data->status;
            $user->save();

            $this->audit->execute(AuditAction::CreateUser, $actor, $user, null, [
                'name'   => $user->name,
                'email'  => $user->email,
                'phone'  => $user->phone,
                'role'   => $data->role->value,
                'status' => $data->status->value,
            ]);

            return $user;
        });
    }
}
