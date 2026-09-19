<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

class InvalidCurrentPasswordException extends Exception
{
    // Lỗi nghiệp vụ khi mật khẩu hiện tại không đúng. Trả 422 kèm lỗi gắn vào trường current_password
    // để frontend hiển thị ngay dưới ô nhập, giống mọi lỗi validate khác.

    public function __construct(string $message = 'The current password is incorrect.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error('Validation failed.', 422, ['current_password' => [$this->getMessage()]]);
    }
}
