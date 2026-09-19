<?php
namespace App\Actions\Auth;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Enums\AuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogoutAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    public function execute(Request $request): void
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            // Ghi audit trước khi xóa token
            $this->audit->execute(AuditAction::Logout, $user, $user);

            // Xóa token hiện tại của request (chỉ đăng xuất thiết bị này)
            $user->currentAccessToken()->delete();
        });
    }
}
