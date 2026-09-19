<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

class InvalidCredentialsException extends Exception
{
    // Dùng chung một lỗi cho "sai email" và "sai mật khẩu" để không lộ email nào tồn tại trong hệ thống

    public function render(): JsonResponse
    {
        return ApiResponse::error('Invalid credentials.', 401);
    }
}
