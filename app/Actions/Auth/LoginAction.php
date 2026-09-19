<?php 
namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginAction
{
    public function execute(string $email, string $password): string
    {
        $user = User::where('email', $email)->first();

        // Kiểm tra User có tồn tại và Mật khẩu có đúng không
        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Thông tin đăng nhập không chính xác.'],
            ]);
        }

        // Xóa các token cũ nếu muốn (optional) và tạo Token mới
        $user->tokens()->delete();

        // Tra về chuỗi Plain Text Token
        return $user->createToken('auth_token')->plainTextToken;
    }
}