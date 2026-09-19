<?php
namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RegisterUserAction
{
    /**
     * Nhận dữ liệu đã qua Validate và thực hiện tạo User trong DB
     */
    public function execute(array $data): User
    {
        // Hash password bằng Bcrypt/Argon2 chuẩn an toàn trước khi lưu DB
        return User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
    }
}