<?php 
namespace App\Actions\Auth;

use Illuminate\Http\Request;

class LogoutAction
{
    public function execute(Request $request): void
    {
        // Xóa token hiện tại của request (chỉ đăng xuất thiết bị này)
        $request->user()->currentAccessToken()->delete();
    }
}