<?php
namespace App\Actions\Auth;

use App\Exceptions\InvalidCurrentPasswordException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ChangePasswordAction
{
    /**
     * Thực thi việc kiểm tra và cập nhật mật khẩu mới
     */
    public function execute(User $user, array $data): void
    {
        // 1. Kiểm tra mật khẩu hiện tại
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new InvalidCurrentPasswordException('Mật khẩu hiện tại không chính xác.');
        }

        // 2. Cập nhật mật khẩu mới & LƯU VÀO DB
        $user->password = Hash::make($data['new_password']);
        $user->save();

        // 3. Xóa toàn bộ token cũ
        $user->tokens()->delete();
    }
}
